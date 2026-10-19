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

class Delete extends AbstractAction implements HttpPostActionInterface
{
    public function __construct(Context $context, private readonly Planner $planner)
    {
        parent::__construct($context);
    }

    public function execute()
    {
        $id = (int) $this->getRequest()->getParam('id');
        try {
            $this->planner->deleteCampaign($id);
            $this->messageManager->addSuccessMessage(__('The campaign is deleted.'));
        } catch (LocalizedException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
            return $this->back('*/*/edit', ['id' => $id]);
        }
        return $this->back('*/*/index');
    }
}
