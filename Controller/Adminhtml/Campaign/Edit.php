<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\Staging\Controller\Adminhtml\Campaign;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Zero\Staging\Controller\Adminhtml\AbstractAction;
use Zero\Staging\Model\Campaigns;

/**
 * A campaign: when it runs, what it changes, and its buttons.
 */
class Edit extends AbstractAction implements HttpGetActionInterface
{
    public function __construct(Context $context, private readonly Campaigns $campaigns)
    {
        parent::__construct($context);
    }

    public function execute()
    {
        $id = (int) $this->getRequest()->getParam('id');
        $campaign = $id ? $this->campaigns->get($id) : null;
        if ($id && !$campaign) {
            $this->messageManager->addErrorMessage(__('This campaign no longer exists.'));
            return $this->back('*/*/index');
        }
        return $this->page($campaign ? (string) $campaign['name'] : (string) __('New Campaign'));
    }
}
