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
 * Start now: the campaign goes live without waiting for its start.
 */
class Start extends AbstractAction implements HttpPostActionInterface
{
    public function __construct(Context $context, private readonly Planner $planner)
    {
        parent::__construct($context);
    }

    public function execute()
    {
        $id = (int) $this->getRequest()->getParam('id');
        try {
            $result = $this->planner->startNow($id);
            $this->messageManager->addSuccessMessage(__('The campaign is live: %1 changes made.', $result['applied']));
            if ($result['failed']) {
                $this->messageManager->addErrorMessage(__('%1 changes couldn\'t be made: see the list.', $result['failed']));
            }
        } catch (LocalizedException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        }
        return $this->back('*/*/edit', ['id' => $id]);
    }
}
