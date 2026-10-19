<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\Staging\Observer\Preview;

use Magento\Framework\DataObject;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Store\Model\StoreManagerInterface;
use Zero\Staging\Model\Preview\Overlay;
use Zero\Staging\Model\Preview\State;

/**
 * In a preview, loaded products, categories, CMS pages and blocks get the
 * values they will have at the previewed moment: a product page and its
 * cards show the new name and price, the menu the new category name, the
 * page its new title. One observer for every *_load_after event of these
 * (events.xml), told by its target which entity it fills.
 *
 * Switching an entity on or off isn't previewed (a disabled product's page
 * doesn't open in the first place); it happens when the campaign starts.
 */
class EntityOverlay implements ObserverInterface
{
    public function __construct(
        private readonly State $state,
        private readonly Overlay $overlay,
        private readonly StoreManagerInterface $storeManager,
        private readonly string $target = 'product'
    ) {
    }

    public function execute(Observer $observer): void
    {
        if (!$this->state->isActive() || !$this->overlay->ids($this->target)) {
            return;
        }
        $event = $observer->getEvent();
        $items = [];
        foreach (['collection', 'category_collection'] as $name) {
            $collection = $event->getData($name);
            if ($collection instanceof \Magento\Framework\Data\Collection) {
                $items = $collection->getItems();
            }
        }
        if (!$items) {
            $object = $event->getData('data_object') ?? $event->getData('object') ?? $event->getData('product')
                ?? $event->getData('category');
            $items = $object instanceof DataObject ? [$object] : [];
        }
        $storeId = (int) $this->storeManager->getStore()->getId();
        foreach ($items as $item) {
            $values = $this->overlay->values($this->target, (int) $item->getId(), $storeId);
            unset($values['status'], $values['is_active'], $values['content']);
            if ($values) {
                $item->addData($values);
                // A price worked out before (another load_after observer) would stay.
                if ($item instanceof \Magento\Catalog\Model\Product
                    && (array_key_exists('price', $values) || array_key_exists('special_price', $values))
                ) {
                    $item->reloadPriceInfo();
                }
            }
        }
    }
}
