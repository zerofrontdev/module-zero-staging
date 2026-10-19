<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\Staging\Model\Target;

use Magento\Backend\Model\UrlInterface;
use Magento\Catalog\Api\Data\CategoryInterface;
use Magento\Catalog\Model\Category as CategoryModel;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\EntityManager\MetadataPool;
use Magento\Framework\Indexer\IndexerRegistry;
use Magento\Store\Model\StoreManagerInterface;

/**
 * A category's name, description, meta and whether it shows.
 */
class Category extends AbstractEav
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
        return (string) __('Category');
    }

    protected function entityType(): string
    {
        return CategoryModel::ENTITY;
    }

    protected function entityInterface(): string
    {
        return CategoryInterface::class;
    }

    protected function attributes(): array
    {
        return [
            'name' => ['label' => (string) __('Name'), 'type' => 'text'],
            'is_active' => ['label' => (string) __('Enabled'), 'type' => 'boolean'],
            'description' => ['label' => (string) __('Description'), 'type' => 'textarea'],
            'meta_title' => ['label' => (string) __('Meta Title'), 'type' => 'text'],
            'meta_description' => ['label' => (string) __('Meta Description'), 'type' => 'textarea'],
        ];
    }

    protected function indexers(): array
    {
        return ['catalog_category_product', 'catalog_category_flat'];
    }

    public function describe(int $id): ?string
    {
        $item = $this->collectionFactory->create()
            ->addAttributeToSelect('name')
            ->addIdFilter([$id])
            ->setPageSize(1)
            ->getFirstItem();
        return $item->getId() ? $this->path($item) : null;
    }

    public function search(string $query, array $filter = [], int $limit = 20): array
    {
        $collection = $this->collectionFactory->create()
            ->addAttributeToSelect('name')
            ->addAttributeToFilter('level', ['gt' => 1])
            ->setPageSize($limit)
            ->setOrder('path', 'ASC');
        if (trim($query) !== '') {
            $collection->addAttributeToFilter('name', ['like' => '%' . addcslashes(trim($query), '%_\\') . '%']);
        }
        $found = [];
        foreach ($collection as $item) {
            $found[(int) $item->getId()] = $this->path($item);
        }
        return $found;
    }

    public function cacheTags(int $id): array
    {
        return [CategoryModel::CACHE_TAG . '_' . $id, \Magento\Catalog\Model\Product::CACHE_PRODUCT_CATEGORY_TAG . '_' . $id];
    }

    public function adminUrl(int $id): string
    {
        return $this->backendUrl->getUrl('catalog/category/edit', ['id' => $id]);
    }

    private function path(\Magento\Framework\DataObject $item): string
    {
        return trim((string) $item->getData('name')) . ' (#' . $item->getId() . ')';
    }
}
