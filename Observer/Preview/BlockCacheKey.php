<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\Staging\Observer\Preview;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\View\Element\AbstractBlock;
use Zero\Staging\Model\Preview\State;

/**
 * In a preview, a block with a cache (a product's price box, the menu)
 * gets a key of the preview's own: its usual key info plus the preview's,
 * so it neither shows the store's cached HTML nor leaves the preview's for
 * the store. Product cards (Zero_Core) already vary by the HTTP context.
 */
class BlockCacheKey implements ObserverInterface
{
    public function __construct(private readonly State $state)
    {
    }

    public function execute(Observer $observer): void
    {
        $block = $observer->getEvent()->getData('block');
        // Every block: whether it caches is its own (protected) business; a key it doesn't use costs nothing.
        if (!$block instanceof AbstractBlock || !$this->state->isActive()) {
            return;
        }
        $block->setData('cache_key', 'zero_staging_' . sha1(
            json_encode($block->getCacheKeyInfo()) . '|' . $this->state->key()
        ));
    }
}
