<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\Staging\Model\Target;

use Magento\Backend\Model\UrlInterface;
use Magento\Catalog\Model\Product;
use Magento\CatalogRule\Model\Indexer\Rule\RuleProductProcessor;
use Magento\CatalogRule\Model\ResourceModel\Rule as RuleResource;
use Magento\CatalogRule\Model\ResourceModel\Rule\CollectionFactory;
use Magento\CatalogRule\Model\RuleFactory;
use Magento\Catalog\Model\Indexer\Product\Price\Processor as PriceProcessor;
use Magento\Framework\App\ResourceConnection;

/**
 * A catalog price rule: on or off, and its discount. The rule's products
 * get their new prices right away (the rule and price indexers for those
 * products, not the "Apply Rules" button's full rebuild), and their pages
 * are cleaned: the ones it covered before and the ones it covers now.
 */
class CatalogRule implements TargetInterface
{
    /** @var array<int, int[]> Rule => products before the last write */
    private array $touched = [];

    public function __construct(
        private readonly RuleFactory $ruleFactory,
        private readonly RuleResource $ruleResource,
        private readonly CollectionFactory $collectionFactory,
        private readonly RuleProductProcessor $ruleProductProcessor,
        private readonly PriceProcessor $priceProcessor,
        private readonly ResourceConnection $resource,
        private readonly UrlInterface $backendUrl
    ) {
    }

    public function label(): string
    {
        return (string) __('Catalog Price Rule');
    }

    public function isStoreScoped(): bool
    {
        return false;
    }

    public function fields(): array
    {
        return [
            'is_active' => ['label' => (string) __('Active'), 'type' => 'boolean'],
            'discount_amount' => ['label' => (string) __('Discount Amount'), 'type' => 'price'],
        ];
    }

    public function describe(int $id): ?string
    {
        $rule = $this->load($id);
        return $rule ? (string) $rule->getName() : null;
    }

    public function search(string $query, array $filter = [], int $limit = 20): array
    {
        $collection = $this->collectionFactory->create()->setPageSize($limit)->setOrder('name', 'ASC');
        if (trim($query) !== '') {
            $collection->addFieldToFilter('name', ['like' => '%' . addcslashes(trim($query), '%_\\') . '%']);
        }
        $found = [];
        foreach ($collection as $rule) {
            $found[(int) $rule->getId()] = (string) $rule->getName();
        }
        return $found;
    }

    public function read(int $id, int $storeId, array $fields): array
    {
        $rule = $this->load($id);
        $values = [];
        foreach (array_intersect($fields, array_keys($this->fields())) as $code) {
            $values[$code] = ['value' => $rule?->getData($code), 'inherited' => false];
        }
        return $values;
    }

    public function write(int $id, int $storeId, array $values): void
    {
        $rule = $this->load($id);
        if (!$rule) {
            throw new \RuntimeException((string) __('%1 %2 no longer exists.', $this->label(), $id));
        }
        $before = $this->products($id);
        foreach (array_intersect_key($values, $this->fields()) as $code => $entry) {
            $rule->setData($code, $entry['value'] ?? 0);
        }
        $this->ruleResource->save($rule);
        $this->ruleProductProcessor->reindexRow($id, true);
        $products = array_values(array_unique(array_merge($before, $this->products($id))));
        if ($products) {
            $this->priceProcessor->reindexList($products, true);
        }
        $this->touched[$id] = $products;
    }

    public function cacheTags(int $id): array
    {
        return array_map(static fn (int $product) => Product::CACHE_TAG . '_' . $product, $this->touched[$id] ?? []);
    }

    public function adminUrl(int $id): string
    {
        return $this->backendUrl->getUrl('catalog_rule/promo_catalog/edit', ['id' => $id]);
    }

    /**
     * @return int[]
     */
    private function products(int $ruleId): array
    {
        $connection = $this->resource->getConnection();
        return array_map('intval', $connection->fetchCol(
            $connection->select()->distinct()
                ->from($this->resource->getTableName('catalogrule_product'), ['product_id'])
                ->where('rule_id = ?', $ruleId)
        ));
    }

    private function load(int $id): ?\Magento\CatalogRule\Model\Rule
    {
        $rule = $this->ruleFactory->create();
        $this->ruleResource->load($rule, $id);
        return $rule->getId() ? $rule : null;
    }
}
