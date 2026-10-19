<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\Staging\Controller\Preview;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Store\Model\StoreManagerInterface;
use Zero\Staging\Model\Preview\State;

/**
 * /zero_staging/preview/stop: back to the store as it is.
 */
class Stop implements HttpGetActionInterface
{
    public function __construct(
        private readonly State $state,
        private readonly RedirectFactory $redirectFactory,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * @return \Magento\Framework\Controller\ResultInterface
     */
    public function execute()
    {
        $this->state->stop();
        return $this->redirectFactory->create()->setUrl($this->storeManager->getStore()->getBaseUrl());
    }
}
