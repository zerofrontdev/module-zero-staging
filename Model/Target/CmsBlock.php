<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\Staging\Model\Target;

use Magento\Backend\Model\UrlInterface;
use Magento\Cms\Model\BlockFactory;
use Magento\Cms\Model\ResourceModel\Block as BlockResource;
use Magento\Cms\Model\ResourceModel\Block\CollectionFactory;
use Magento\Framework\Model\AbstractModel;
use Zero\Cms\Model\Content\Repository as ContentRepository;

/**
 * A CMS block: title, status and its Zero CMS content.
 */
class CmsBlock extends AbstractCms
{
    public function __construct(
        ContentRepository $contentRepository,
        private readonly BlockFactory $blockFactory,
        private readonly BlockResource $blockResource,
        private readonly CollectionFactory $collectionFactory,
        private readonly UrlInterface $backendUrl
    ) {
        parent::__construct($contentRepository);
    }

    public function label(): string
    {
        return (string) __('CMS Block');
    }

    protected function contentType(): string
    {
        return ContentRepository::TYPE_BLOCK;
    }

    protected function entityFields(): array
    {
        return [
            'title' => ['label' => (string) __('Title'), 'type' => 'text'],
            'is_active' => ['label' => (string) __('Enabled'), 'type' => 'boolean'],
        ];
    }

    protected function load(int $id): ?AbstractModel
    {
        $block = $this->blockFactory->create();
        $this->blockResource->load($block, $id);
        return $block->getId() ? $block : null;
    }

    protected function persist(AbstractModel $entity): void
    {
        $this->blockResource->save($entity);
    }

    public function describe(int $id): ?string
    {
        $block = $this->load($id);
        return $block ? $block->getData('title') . ' (' . $block->getData('identifier') . ')' : null;
    }

    public function search(string $query, array $filter = [], int $limit = 20): array
    {
        $collection = $this->collectionFactory->create()->setPageSize($limit)->setOrder('title', 'ASC');
        if (trim($query) !== '') {
            $like = '%' . addcslashes(trim($query), '%_\\') . '%';
            $collection->addFieldToFilter(['title', 'identifier'], [['like' => $like], ['like' => $like]]);
        }
        $found = [];
        foreach ($collection as $block) {
            $found[(int) $block->getId()] = $block->getData('title') . ' (' . $block->getData('identifier') . ')';
        }
        return $found;
    }

    public function adminUrl(int $id): string
    {
        return $this->backendUrl->getUrl('cms/block/edit', ['block_id' => $id]);
    }
}
