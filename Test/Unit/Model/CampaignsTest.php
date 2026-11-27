<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\Staging\Test\Unit\Model;

use Magento\Backend\Model\UrlInterface;
use Magento\Cms\Model\PageFactory;
use Magento\Cms\Model\ResourceModel\Page as PageResource;
use Magento\Cms\Model\ResourceModel\Page\CollectionFactory;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use PHPUnit\Framework\TestCase;
use Zero\Cms\Model\Content\Repository as ContentRepository;
use Zero\Staging\Model\Campaigns;
use Zero\Staging\Model\Target\CmsPage;
use Zero\Staging\Model\Target\Pool;
use Zero\Staging\Model\Target\TargetInterface;

/**
 * Two campaigns clash on a field when its values land in a common store
 * view (the target's write rule, stores()): a global field from any two
 * store views, a website field within one website, a store-view field only
 * in the same store view, a CMS page's own fields everywhere and its
 * content per store view. All Store Views against one store view of a
 * store-view or website field is no clash. The store id is never a SQL
 * filter any more; names come back once each.
 */
class CampaignsTest extends TestCase
{
    /** @var array<int, array{name: string, data: string, store_id: string}> what the join returns */
    private array $rows = [];
    /** @var array<int, array{0: mixed, 1: mixed}> every where() condition the query was built with, and its value */
    private array $wheres = [];

    /**
     * A product-like target: price global, special_price by website (stores 1 and 2 on website A, 3 on B), name per store view.
     */
    private function product(): TargetInterface
    {
        $target = $this->createStub(TargetInterface::class);
        $target->method('stores')->willReturnCallback(static function (string $field, int $storeId): array {
            if ($storeId === 0 || $field === 'price') {
                return [0];
            }
            if ($field === 'special_price') {
                return in_array($storeId, [1, 2], true) ? [1, 2] : [$storeId];
            }
            return [$storeId];
        });
        return $target;
    }

    private function campaigns(array $targets): Campaigns
    {
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('join')->willReturnSelf();
        $select->method('where')->willReturnCallback(function ($condition, $value = null) use ($select) {
            $this->wheres[] = [$condition, $value];
            return $select;
        });
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchAll')->willReturnCallback(fn () => $this->rows);
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);
        return new Campaigns($resource, new Pool($targets));
    }

    private function row(string $name, array $data, int $storeId): array
    {
        return ['name' => $name, 'data' => (string) json_encode($data), 'store_id' => (string) $storeId];
    }

    /**
     * @param string[] $fields
     * @return string[]
     */
    private function conflicts(int $storeId, array $fields, string $target = 'product'): array
    {
        return $this->campaigns(['product' => $this->product(), 'cms_page' => $this->cmsPage()])
            ->conflicts(9, '2026-11-27 00:00:00', '2026-11-30 00:00:00', $target, 42, $storeId, $fields);
    }

    private function cmsPage(): CmsPage
    {
        return new CmsPage(
            $this->createStub(ContentRepository::class),
            $this->createStub(PageFactory::class),
            $this->createStub(PageResource::class),
            $this->createStub(CollectionFactory::class),
            $this->createStub(UrlInterface::class)
        );
    }

    public function testAGlobalFieldClashesAcrossStoreViews(): void
    {
        $this->rows = [$this->row('Black Friday', ['price' => 27], 2)];
        self::assertSame(['Black Friday'], $this->conflicts(1, ['price']));
    }

    public function testAWebsiteFieldClashesWithinItsWebsiteOnly(): void
    {
        $this->rows = [$this->row('EU sale', ['special_price' => 9.99], 2)];
        self::assertSame(['EU sale'], $this->conflicts(1, ['special_price']), 'stores 1 and 2 share a website');

        $this->rows = [$this->row('Other website', ['special_price' => 9.99], 3)];
        self::assertSame([], $this->conflicts(1, ['special_price']), 'store 3 is on another website');
    }

    public function testAStoreViewFieldClashesOnlyInTheSameStoreView(): void
    {
        $this->rows = [$this->row('Rename', ['name' => 'Tee'], 2)];
        self::assertSame([], $this->conflicts(1, ['name']));

        $this->rows = [$this->row('Rename', ['name' => 'Tee'], 1)];
        self::assertSame(['Rename'], $this->conflicts(1, ['name']), 'equal store ids always clash');
    }

    public function testAllStoreViewsAgainstOneStoreViewIsNoClash(): void
    {
        $this->rows = [$this->row('Default name', ['name' => 'Tee'], 0), $this->row('Default special', ['special_price' => 5], 0)];
        self::assertSame([], $this->conflicts(1, ['name', 'special_price']));
        $this->rows = [$this->row('Store one', ['special_price' => 5], 1)];
        self::assertSame([], $this->conflicts(0, ['special_price']));
    }

    public function testOtherFieldsDoNotClash(): void
    {
        $this->rows = [$this->row('Price only', ['price' => 27], 1)];
        self::assertSame([], $this->conflicts(1, ['name', 'special_price']));
    }

    public function testACmsPagesOwnFieldsClashEverywhereItsContentPerStoreView(): void
    {
        $this->rows = [$this->row('New title', ['title' => 'Sale'], 2)];
        self::assertSame(['New title'], $this->conflicts(1, ['title'], 'cms_page'));

        $this->rows = [$this->row('New content', ['content' => ['components' => []]], 2)];
        self::assertSame([], $this->conflicts(1, ['content'], 'cms_page'));
        $this->rows = [$this->row('New content', ['content' => ['components' => []]], 1)];
        self::assertSame(['New content'], $this->conflicts(1, ['content'], 'cms_page'));
    }

    public function testTheSameCampaignIsNamedOnce(): void
    {
        $this->rows = [
            $this->row('Black Friday', ['price' => 27], 1),
            $this->row('Black Friday', ['price' => 25, 'name' => 'Tee'], 2),
            $this->row('Cyber Monday', ['price' => 24], 3),
        ];
        self::assertSame(['Black Friday', 'Cyber Monday'], $this->conflicts(1, ['price', 'name']));
    }

    public function testAnUnknownTargetComparesRawStoreIds(): void
    {
        $this->rows = [$this->row('Elsewhere', ['x' => 1], 2), $this->row('Same', ['x' => 1], 1)];
        self::assertSame(['Same'], $this->conflicts(1, ['x'], 'gone_kind'));
    }

    public function testTheStoreViewIsNotFilteredInSql(): void
    {
        $this->rows = [];
        $this->conflicts(1, ['price']);
        self::assertSame([
            ['c.campaign_id != ?', 9],
            ['c.status IN (?)', [Campaigns::SCHEDULED, Campaigns::ACTIVE]],
            ['ch.target = ?', 'product'],
            ['ch.entity_id = ?', 42],
            ['c.end_at IS NULL OR c.end_at > ?', '2026-11-27 00:00:00'],
            ['c.start_at < ?', '2026-11-30 00:00:00'],
        ], $this->wheres, 'no store_id filter: the store views are compared per field afterwards');
    }

    public function testACampaignWithoutAnEndClashesWithAnyLaterStart(): void
    {
        $this->rows = [];
        $this->campaigns(['product' => $this->product()])->conflicts(3, '2026-12-01 00:00:00', null, 'product', 7, 0, ['price']);
        self::assertSame(['c.end_at IS NULL OR c.end_at > ?', '2026-12-01 00:00:00'], end($this->wheres));
        self::assertNotContains('c.start_at < ?', array_column($this->wheres, 0));
        self::assertSame(['c.campaign_id != ?', 3], $this->wheres[0]);
    }
}
