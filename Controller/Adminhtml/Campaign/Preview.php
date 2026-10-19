<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\Staging\Controller\Adminhtml\Campaign;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Store\Model\StoreManagerInterface;
use Zero\Staging\Controller\Adminhtml\AbstractAction;
use Zero\Staging\Model\Campaigns;
use Zero\Staging\Model\Clock;
use Zero\Staging\Model\Preview\Link;

/**
 * Opens the storefront as of a moment: a campaign's start (?id=), or any
 * date and time (?at=, store time), in a store view (?store=, default the
 * default one).
 */
class Preview extends AbstractAction implements HttpGetActionInterface
{
    public function __construct(
        Context $context,
        private readonly Campaigns $campaigns,
        private readonly Clock $clock,
        private readonly Link $link,
        private readonly StoreManagerInterface $storeManager
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $id = (int) $this->getRequest()->getParam('id');
        $campaign = $id ? $this->campaigns->get($id) : null;
        $at = $this->clock->toUtc((string) $this->getRequest()->getParam('at', ''))
            ?? ($campaign ? max((string) $campaign['start_at'], $this->clock->now()) : null);
        if ($at === null) {
            $this->messageManager->addErrorMessage(__('Choose a date and time to preview.'));
            return $this->back('*/*/index');
        }
        $storeId = (int) $this->getRequest()->getParam('store')
            ?: (int) $this->storeManager->getDefaultStoreView()?->getId();
        return $this->resultRedirectFactory->create()->setUrl($this->link->url($storeId, $at, $campaign ? $id : 0));
    }
}
