<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\Staging\Model\Target;

use Magento\Backend\Model\UrlInterface;
use Magento\SalesRule\Model\ResourceModel\Rule as RuleResource;
use Magento\SalesRule\Model\ResourceModel\Rule\CollectionFactory;
use Magento\SalesRule\Model\RuleFactory;

/**
 * A cart price rule: on or off, and its discount. Its own From/To dates
 * stay as they are; a campaign switches it at the minute, not the day.
 */
class CartRule implements TargetInterface
{
    public function __construct(
        private readonly RuleFactory $ruleFactory,
        private readonly RuleResource $ruleResource,
        private readonly CollectionFactory $collectionFactory,
        private readonly UrlInterface $backendUrl
    ) {
    }

    public function label(): string
    {
        return (string) __('Cart Price Rule');
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
        foreach (array_intersect_key($values, $this->fields()) as $code => $entry) {
            $rule->setData($code, $entry['value'] ?? 0);
        }
        $this->ruleResource->save($rule);
    }

    /** One value for every store view. */
    public function stores(string $field, int $storeId): array
    {
        return [0];
    }

    public function cacheTags(int $id): array
    {
        return [];
    }

    public function adminUrl(int $id): string
    {
        return $this->backendUrl->getUrl('sales_rule/promo_quote/edit', ['id' => $id]);
    }

    private function load(int $id): ?\Magento\SalesRule\Model\Rule
    {
        $rule = $this->ruleFactory->create();
        $this->ruleResource->load($rule, $id);
        return $rule->getId() ? $rule : null;
    }
}
