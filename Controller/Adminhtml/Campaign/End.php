<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\Staging\Controller\Adminhtml\Campaign;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Exception\LocalizedException;
use Zero\Staging\Controller\Adminhtml\AbstractAction;
use Zero\Staging\Model\Planner;

/**
 * End now: a live campaign is undone without waiting for its end.
 */
class End extends AbstractAction implements HttpPostActionInterface
{
    public function __construct(Context $context, private readonly Planner $planner)
    {
        parent::__construct($context);
    }

    public function execute()
    {
        $id = (int) $this->getRequest()->getParam('id');
        try {
            $result = $this->planner->endNow($id);
            $this->messageManager->addSuccessMessage(__('The campaign ended: %1 changes undone.', $result['reverted']));
            if ($result['kept']) {
                $this->messageManager->addNoticeMessage(__(
                    '%1 fields were changed by hand during the campaign and keep those values.',
                    $result['kept']
                ));
            }
        } catch (LocalizedException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        }
        return $this->back('*/*/edit', ['id' => $id]);
    }
}
