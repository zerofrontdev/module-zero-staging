<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\Staging\Test\Unit\Model\Target;

use Magento\Backend\Model\UrlInterface;
use Magento\Catalog\Model\ResourceModel\Eav\Attribute;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\EntityManager\MetadataPool;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Indexer\IndexerRegistry;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Store\Model\Website;
use PHPUnit\Framework\TestCase;
use Zero\Staging\Model\Target\BannerSlide;
use Zero\Staging\Model\Target\CartRule;
use Zero\Staging\Model\Target\CatalogRule;
use Zero\Staging\Model\Target\Product;

/**
 * Where a value lands (TargetInterface::stores(), the rule the write and the
 * conflict check share): a global attribute in All Store Views, a website
 * one in every store view of the website, a store-view one where it was
 * set, All Store Views stays there, a store view gone falls back to itself;
 * a price attribute asks its backend first (Catalog Price Scope). Banner
 * slides and rules have no store views.
 */
class StoresTest extends TestCase
{
    private bool $global = false;
    private bool $website = false;

    private function product(?Attribute $attribute, ?StoreManagerInterface $stores = null): Product
    {
        $eav = $this->createStub(EavConfig::class);
        $eav->method('getAttribute')->willReturn($attribute);
        return new Product(
            $this->createStub(ResourceConnection::class),
            $eav,
            $this->createStub(MetadataPool::class),
            $stores ?? $this->websiteOf([1 => ['1', '2']]),
            $this->createStub(IndexerRegistry::class),
            $this->createStub(ProductCollectionFactory::class),
            $this->createStub(UrlInterface::class)
        );
    }

    private function attribute(): Attribute
    {
        $attribute = $this->createStub(Attribute::class);
        $attribute->method('getId')->willReturn('77');
        $attribute->method('isStatic')->willReturn(false);
        $attribute->method('isScopeGlobal')->willReturnCallback(fn () => $this->global);
        $attribute->method('isScopeWebsite')->willReturnCallback(fn () => $this->website);
        return $attribute;
    }

    /**
     * @param array<int, string[]> $websites store id => the store ids of its website
     */
    private function websiteOf(array $websites): StoreManagerInterface
    {
        $manager = $this->createStub(StoreManagerInterface::class);
        $manager->method('getStore')->willReturnCallback(function ($id) use ($websites) {
            if (!isset($websites[(int) $id])) {
                throw new NoSuchEntityException(__('The store that was requested wasn\'t found.'));
            }
            $website = $this->createStub(Website::class);
            $website->method('getStoreIds')->willReturn($websites[(int) $id]);
            $store = $this->createStub(Store::class);
            $store->method('getWebsite')->willReturn($website);
            return $store;
        });
        return $manager;
    }

    public function testGlobalGoesToAllStoreViews(): void
    {
        $this->global = true;
        self::assertSame([0], $this->product($this->attribute())->stores('price', 1));
    }

    public function testWebsiteGoesToEveryStoreViewOfTheWebsite(): void
    {
        $this->website = true;
        self::assertSame([1, 2], $this->product($this->attribute())->stores('special_price', 1));
    }

    public function testStoreScopeStaysInItsStoreView(): void
    {
        self::assertSame([5], $this->product($this->attribute())->stores('name', 5));
    }

    public function testAllStoreViewsStaysAtZero(): void
    {
        $this->website = true;
        self::assertSame([0], $this->product($this->attribute())->stores('special_price', 0));
    }

    public function testAGoneStoreViewFallsBackToItself(): void
    {
        $this->website = true;
        self::assertSame([9], $this->product($this->attribute(), $this->websiteOf([]))->stores('special_price', 9));
    }

    public function testAnUnknownAttributeStaysInItsStoreView(): void
    {
        self::assertSame([4], $this->product(null)->stores('gone', 4));
    }

    public function testThePriceScopeIsAskedOfTheBackend(): void
    {
        // The attribute says global until its backend applies Catalog Price Scope = website (Backend\Price::setScope()).
        $this->global = true;
        $attribute = $this->createMock(Attribute::class);
        $attribute->method('getId')->willReturn('77');
        $attribute->method('isStatic')->willReturn(false);
        $attribute->method('isScopeGlobal')->willReturnCallback(fn () => $this->global);
        $attribute->method('isScopeWebsite')->willReturnCallback(fn () => $this->website);
        $attribute->expects(self::once())->method('getBackend')->willReturnCallback(function () {
            $this->global = false;
            $this->website = true;
            return null;
        });
        self::assertSame([1, 2], $this->product($attribute)->stores('price', 1));
    }

    public function testBannerSlidesAndRulesHaveNoStoreViews(): void
    {
        $slide = new BannerSlide(
            $this->createStub(\Zero\Banner\Model\SlideFactory::class),
            $this->createStub(\Zero\Banner\Model\ResourceModel\Slide::class),
            $this->createStub(ResourceConnection::class),
            $this->createStub(UrlInterface::class)
        );
        $catalogRule = new CatalogRule(
            $this->createStub(\Magento\CatalogRule\Model\RuleFactory::class),
            $this->createStub(\Magento\CatalogRule\Model\ResourceModel\Rule::class),
            $this->createStub(\Magento\CatalogRule\Model\ResourceModel\Rule\CollectionFactory::class),
            $this->createStub(\Magento\CatalogRule\Model\Indexer\Rule\RuleProductProcessor::class),
            $this->createStub(\Magento\Catalog\Model\Indexer\Product\Price\Processor::class),
            $this->createStub(ResourceConnection::class),
            $this->createStub(UrlInterface::class)
        );
        $cartRule = new CartRule(
            $this->createStub(\Magento\SalesRule\Model\RuleFactory::class),
            $this->createStub(\Magento\SalesRule\Model\ResourceModel\Rule::class),
            $this->createStub(\Magento\SalesRule\Model\ResourceModel\Rule\CollectionFactory::class),
            $this->createStub(UrlInterface::class)
        );
        self::assertSame([[0], [0], [0], [0]], [
            $slide->stores('title', 2),
            $catalogRule->stores('discount_amount', 3),
            $cartRule->stores('is_active', 1),
            $cartRule->stores('is_active', 0),
        ]);
    }
}
