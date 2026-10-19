<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\Staging\Model\Target;

use Magento\Backend\Model\UrlInterface;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Model\Product as ProductModel;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\EntityManager\MetadataPool;
use Magento\Framework\Indexer\IndexerRegistry;
use Magento\Store\Model\StoreManagerInterface;

/**
 * A product's name, texts, price, special price, status and badge.
 */
class Product extends AbstractEav
{
    public function __construct(
        ResourceConnection $resource,
        EavConfig $eavConfig,
        MetadataPool $metadataPool,
        StoreManagerInterface $storeManager,
        IndexerRegistry $indexerRegistry,
        private readonly CollectionFactory $collectionFactory,
        private readonly UrlInterface $backendUrl
    ) {
        parent::__construct($resource, $eavConfig, $metadataPool, $storeManager, $indexerRegistry);
    }

    public function label(): string
    {
        return (string) __('Product');
    }

    protected function entityType(): string
    {
        return ProductModel::ENTITY;
    }

    protected function entityInterface(): string
    {
        return ProductInterface::class;
    }

    protected function attributes(): array
    {
        return [
            'name' => ['label' => (string) __('Name'), 'type' => 'text'],
            'price' => ['label' => (string) __('Price'), 'type' => 'price'],
            'special_price' => [
                'label' => (string) __('Special Price'),
                'type' => 'price',
                'hint' => (string) __('Empty removes it.'),
            ],
            'status' => ['label' => (string) __('Status'), 'type' => 'select'],
            'zero_badge' => ['label' => (string) __('Badge'), 'type' => 'select'],
            'short_description' => ['label' => (string) __('Short Description'), 'type' => 'textarea'],
            'description' => ['label' => (string) __('Description'), 'type' => 'textarea'],
        ];
    }

    protected function indexers(): array
    {
        return ['catalog_product_attribute', 'catalog_product_price', 'catalog_category_product', 'catalogsearch_fulltext'];
    }

    public function describe(int $id): ?string
    {
        $item = $this->collectionFactory->create()
            ->addAttributeToSelect('name')
            ->addIdFilter($id)
            ->setPageSize(1)
            ->getFirstItem();
        return $item->getId() ? $this->name($item) : null;
    }

    public function search(string $query, array $filter = [], int $limit = 20): array
    {
        $collection = $this->collectionFactory->create()
            ->addAttributeToSelect('name')
            ->setPageSize($limit)
            ->setOrder('name', 'ASC');
        $query = trim($query);
        if ($query !== '') {
            $like = '%' . addcslashes($query, '%_\\') . '%';
            $collection->addAttributeToFilter([
                ['attribute' => 'name', 'like' => $like],
                ['attribute' => 'sku', 'like' => $like],
            ]);
        }
        $found = [];
        foreach ($collection as $item) {
            $found[(int) $item->getId()] = $this->name($item);
        }
        return $found;
    }

    public function cacheTags(int $id): array
    {
        return [ProductModel::CACHE_TAG . '_' . $id];
    }

    public function adminUrl(int $id): string
    {
        return $this->backendUrl->getUrl('catalog/product/edit', ['id' => $id]);
    }

    private function name(\Magento\Framework\DataObject $item): string
    {
        return trim((string) $item->getData('name')) . ' (' . $item->getData('sku') . ')';
    }
}
