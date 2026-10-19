<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\Staging\Controller\Adminhtml;

use Magento\Backend\App\Action;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\ResultFactory;

/**
 * Content > Staging.
 */
abstract class AbstractAction extends Action
{
    public const ADMIN_RESOURCE = 'Zero_Staging::staging';

    protected function page(string $title): \Magento\Framework\View\Result\Page
    {
        /** @var \Magento\Framework\View\Result\Page $page */
        $page = $this->resultFactory->create(ResultFactory::TYPE_PAGE);
        $page->setActiveMenu('Zero_Staging::campaigns');
        $page->getConfig()->getTitle()->prepend(__('Campaigns'));
        $page->getConfig()->getTitle()->prepend($title);
        return $page;
    }

    protected function back(string $route, array $params = []): \Magento\Framework\Controller\Result\Redirect
    {
        return $this->resultRedirectFactory->create()->setPath($route, $params);
    }

    protected function json(array $data, int $status = 200): Json
    {
        /** @var Json $result */
        $result = $this->resultFactory->create(ResultFactory::TYPE_JSON);
        return $result->setHttpResponseCode($status)->setData($data);
    }

    protected function adminName(): string
    {
        return (string) ($this->_auth->getUser()?->getUserName() ?? '');
    }
}
