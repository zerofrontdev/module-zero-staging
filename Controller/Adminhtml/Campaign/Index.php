<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\Staging\Controller\Adminhtml\Campaign;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Zero\Staging\Controller\Adminhtml\AbstractAction;

/**
 * Content > Staging > Campaigns: the next weeks on a timeline, then every
 * campaign coming up, live and done.
 */
class Index extends AbstractAction implements HttpGetActionInterface
{
    public function execute()
    {
        $page = $this->resultFactory->create(\Magento\Framework\Controller\ResultFactory::TYPE_PAGE);
        $page->setActiveMenu('Zero_Staging::campaigns');
        $page->getConfig()->getTitle()->prepend(__('Campaigns'));
        return $page;
    }
}
