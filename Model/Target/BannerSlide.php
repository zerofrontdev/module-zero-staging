<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\Staging\Model\Target;

use Magento\Backend\Model\UrlInterface;
use Magento\Framework\App\ResourceConnection;
use Zero\Banner\Model\Banner;
use Zero\Banner\Model\ResourceModel\Slide as SlideResource;
use Zero\Banner\Model\SlideFactory;

/**
 * A banner slide: its texts, link, pictures and whether it shows. Slides
 * belong to the banner, not to a store view.
 */
class BannerSlide implements TargetInterface
{
    public function __construct(
        private readonly SlideFactory $slideFactory,
        private readonly SlideResource $slideResource,
        private readonly ResourceConnection $resource,
        private readonly UrlInterface $backendUrl
    ) {
    }

    public function label(): string
    {
        return (string) __('Banner Slide');
    }

    public function isStoreScoped(): bool
    {
        return false;
    }

    public function fields(): array
    {
        return [
            'is_active' => ['label' => (string) __('Enabled'), 'type' => 'boolean'],
            'eyebrow' => ['label' => (string) __('Eyebrow'), 'type' => 'text'],
            'heading' => ['label' => (string) __('Heading'), 'type' => 'text'],
            'text' => ['label' => (string) __('Text'), 'type' => 'textarea'],
            'button_text' => ['label' => (string) __('Button Text'), 'type' => 'text'],
            'link_url' => ['label' => (string) __('Link URL'), 'type' => 'text'],
            'image' => [
                'label' => (string) __('Picture'),
                'type' => 'text',
                'hint' => (string) __('Media path of a picture uploaded to a banner (zero_banner/…).'),
            ],
            'mobile_image' => ['label' => (string) __('Phone Picture'), 'type' => 'text'],
            'alt' => ['label' => (string) __('Alt Text'), 'type' => 'text'],
        ];
    }

    public function describe(int $id): ?string
    {
        $rows = $this->rows(static fn ($select) => $select->where('s.slide_id = ?', $id), 1);
        return $rows[$id] ?? null;
    }

    public function search(string $query, array $filter = [], int $limit = 20): array
    {
        $connection = $this->resource->getConnection();
        return $this->rows(static function ($select) use ($query, $filter, $connection) {
            if (!empty($filter['banner'])) {
                $select->where('s.banner_id = ?', (int) $filter['banner']);
            }
            if (trim($query) !== '') {
                $like = '%' . addcslashes(trim($query), '%_\\') . '%';
                $select->where($connection->quoteInto('b.name LIKE ?', $like) . ' OR ' . $connection->quoteInto('s.heading LIKE ?', $like));
            }
        }, $limit);
    }

    public function read(int $id, int $storeId, array $fields): array
    {
        $slide = $this->load($id);
        $values = [];
        foreach (array_intersect($fields, array_keys($this->fields())) as $code) {
            $values[$code] = ['value' => $slide->getData($code), 'inherited' => false];
        }
        return $values;
    }

    public function write(int $id, int $storeId, array $values): void
    {
        $slide = $this->load($id);
        foreach (array_intersect_key($values, $this->fields()) as $code => $entry) {
            $slide->setData($code, $entry['value'] ?? null);
        }
        $this->slideResource->save($slide);
    }

    public function cacheTags(int $id): array
    {
        $bannerId = (int) $this->load($id)->getData('banner_id');
        return [Banner::CACHE_TAG, Banner::CACHE_TAG . '_' . $bannerId];
    }

    public function adminUrl(int $id): string
    {
        $bannerId = (int) $this->load($id)->getData('banner_id');
        return $bannerId ? $this->backendUrl->getUrl('zero_banner/banner/edit', ['banner_id' => $bannerId]) : '';
    }

    private function load(int $id): \Zero\Banner\Model\Slide
    {
        $slide = $this->slideFactory->create();
        $this->slideResource->load($slide, $id);
        if (!$slide->getId()) {
            throw new \RuntimeException((string) __('%1 %2 no longer exists.', $this->label(), $id));
        }
        return $slide;
    }

    /**
     * "Spring hero, slide 2: New in" per slide.
     *
     * @param callable(\Magento\Framework\DB\Select): void $filter
     * @return array<int, string>
     */
    private function rows(callable $filter, int $limit): array
    {
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from(['s' => $this->resource->getTableName('zero_banner_slide')], ['slide_id', 'position', 'heading'])
            ->join(['b' => $this->resource->getTableName('zero_banner_entity')], 'b.banner_id = s.banner_id', ['name'])
            ->order(['b.name', 's.position'])
            ->limit($limit);
        $filter($select);
        $found = [];
        foreach ($connection->fetchAll($select) as $row) {
            $found[(int) $row['slide_id']] = (string) __(
                '%1, slide %2: %3',
                $row['name'],
                (int) $row['position'] + 1,
                trim((string) $row['heading']) ?: '—'
            );
        }
        return $found;
    }
}
