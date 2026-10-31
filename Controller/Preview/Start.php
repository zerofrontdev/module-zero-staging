<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\Staging\Controller\Preview;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\ForwardFactory;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Store\Model\StoreManagerInterface;
use Zero\Staging\Model\Preview\State;

/**
 * /zero_staging/preview/start?p=…: the admin's signed link. Keeps the
 * preview in a cookie and opens the home page as of that moment.
 */
class Start implements HttpGetActionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly State $state,
        private readonly RedirectFactory $redirectFactory,
        private readonly ForwardFactory $forwardFactory,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * @return \Magento\Framework\Controller\ResultInterface
     */
    public function execute()
    {
        $pParam = $this->request->getParam('p', '');
        if (!$this->state->start(is_scalar($pParam) ? (string) $pParam : '')) {
            return $this->forwardFactory->create()->forward('noroute');
        }
        return $this->redirectFactory->create()->setUrl($this->storeManager->getStore()->getBaseUrl());
    }
}
