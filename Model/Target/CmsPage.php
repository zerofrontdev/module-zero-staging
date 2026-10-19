<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\Staging\Model\Target;

use Magento\Backend\Model\UrlInterface;
use Magento\Cms\Model\PageFactory;
use Magento\Cms\Model\ResourceModel\Page as PageResource;
use Magento\Cms\Model\ResourceModel\Page\CollectionFactory;
use Magento\Framework\Model\AbstractModel;
use Zero\Cms\Model\Content\Repository as ContentRepository;

/**
 * A CMS page: title, heading, meta, status and its Zero CMS content.
 */
class CmsPage extends AbstractCms
{
    public function __construct(
        ContentRepository $contentRepository,
        private readonly PageFactory $pageFactory,
        private readonly PageResource $pageResource,
        private readonly CollectionFactory $collectionFactory,
        private readonly UrlInterface $backendUrl
    ) {
        parent::__construct($contentRepository);
    }

    public function label(): string
    {
        return (string) __('CMS Page');
    }

    protected function contentType(): string
    {
        return ContentRepository::TYPE_PAGE;
    }

    protected function entityFields(): array
    {
        return [
            'title' => ['label' => (string) __('Title'), 'type' => 'text'],
            'content_heading' => ['label' => (string) __('Content Heading'), 'type' => 'text'],
            'is_active' => ['label' => (string) __('Enabled'), 'type' => 'boolean'],
            'meta_title' => ['label' => (string) __('Meta Title'), 'type' => 'text'],
            'meta_description' => ['label' => (string) __('Meta Description'), 'type' => 'textarea'],
        ];
    }

    protected function load(int $id): ?AbstractModel
    {
        $page = $this->pageFactory->create();
        $this->pageResource->load($page, $id);
        return $page->getId() ? $page : null;
    }

    protected function persist(AbstractModel $entity): void
    {
        $this->pageResource->save($entity);
    }

    public function describe(int $id): ?string
    {
        $page = $this->load($id);
        return $page ? $page->getData('title') . ' (/' . $page->getData('identifier') . ')' : null;
    }

    public function search(string $query, array $filter = [], int $limit = 20): array
    {
        $collection = $this->collectionFactory->create()->setPageSize($limit)->setOrder('title', 'ASC');
        if (trim($query) !== '') {
            $like = '%' . addcslashes(trim($query), '%_\\') . '%';
            $collection->addFieldToFilter(['title', 'identifier'], [['like' => $like], ['like' => $like]]);
        }
        $found = [];
        foreach ($collection as $page) {
            $found[(int) $page->getId()] = $page->getData('title') . ' (/' . $page->getData('identifier') . ')';
        }
        return $found;
    }

    public function adminUrl(int $id): string
    {
        return $this->backendUrl->getUrl('cms/page/edit', ['page_id' => $id]);
    }
}
