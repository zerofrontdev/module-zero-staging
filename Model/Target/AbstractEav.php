<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\Staging\Model\Target;

use Magento\Eav\Model\Config as EavConfig;
use Magento\Eav\Model\Entity\Attribute\AbstractAttribute;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\EntityManager\MetadataPool;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Indexer\IndexerRegistry;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Products and categories: attribute values in the EAV tables, read and
 * written per store view the way the admin's "Use Default Value" works. A
 * store view without its own value inherits; undoing a change that gave it
 * one removes it again. Global attributes (price, by default) live in
 * All Store Views whatever the change's store view; website ones in every
 * store view of the website. The conflict check between campaigns counts
 * the same store views (stores()).
 *
 * Written straight to the tables (no full entity save, so no other
 * attribute is touched), then the entity's indexers that don't run by
 * schedule are brought up to date; scheduled ones pick it up from their
 * triggers.
 */
abstract class AbstractEav implements TargetInterface
{
    public function __construct(
        protected readonly ResourceConnection $resource,
        protected readonly EavConfig $eavConfig,
        protected readonly MetadataPool $metadataPool,
        protected readonly StoreManagerInterface $storeManager,
        protected readonly IndexerRegistry $indexerRegistry
    ) {
    }

    /** catalog_product, catalog_category */
    abstract protected function entityType(): string;

    /** The entity's data interface, for its link field (row_id on Adobe Commerce). */
    abstract protected function entityInterface(): string;

    /**
     * The attributes a change may set, with how the form shows them.
     *
     * @return array<string, array{label: string, type: string, hint?: string}>
     */
    abstract protected function attributes(): array;

    /** Indexers to bring up to date after a write (those not on schedule). */
    abstract protected function indexers(): array;

    public function isStoreScoped(): bool
    {
        return true;
    }

    public function fields(): array
    {
        $fields = [];
        foreach ($this->attributes() as $code => $field) {
            $attribute = $this->attribute($code);
            if (!$attribute) {
                continue;
            }
            if ($field['type'] === 'select' && empty($field['options']) && $attribute->usesSource()) {
                $field['options'] = [];
                foreach ($attribute->getSource()->getAllOptions(false) as $option) {
                    if ((string) $option['value'] !== '') {
                        $field['options'][(string) $option['value']] = (string) $option['label'];
                    }
                }
            }
            if ($attribute->isScopeGlobal()) {
                $field['hint'] = trim(($field['hint'] ?? '') . ' ' . __('Same in every store view.'));
            }
            $fields[$code] = $field;
        }
        return $fields;
    }

    public function read(int $id, int $storeId, array $fields): array
    {
        $link = $this->linkId($id);
        $values = [];
        foreach ($fields as $code) {
            $attribute = $this->attribute($code);
            if (!$attribute || $link === null) {
                continue;
            }
            $store = $attribute->isScopeGlobal() ? 0 : $storeId;
            $own = $this->value($attribute, $link, $store);
            if ($own === false && $store !== 0) {
                $values[$code] = ['value' => $this->nullable($this->value($attribute, $link, 0)), 'inherited' => true];
            } else {
                $values[$code] = ['value' => $this->nullable($own), 'inherited' => false];
            }
        }
        return $values;
    }

    public function write(int $id, int $storeId, array $values): void
    {
        $link = $this->linkId($id);
        if ($link === null) {
            throw new \RuntimeException((string) __('%1 %2 no longer exists.', $this->label(), $id));
        }
        $connection = $this->resource->getConnection();
        $linkField = $this->linkField();
        foreach ($values as $code => $entry) {
            $attribute = $this->attribute($code);
            if (!$attribute) {
                continue;
            }
            $table = $attribute->getBackendTable();
            foreach ($this->writeStores($attribute, $storeId) as $store) {
                $where = [
                    'attribute_id = ?' => (int) $attribute->getId(),
                    'store_id = ?' => $store,
                    $linkField . ' = ?' => $link,
                ];
                $value = $entry['value'] ?? null;
                if ((!empty($entry['inherited']) && $store !== 0) || $value === null) {
                    $connection->delete($table, $where);
                    continue;
                }
                $connection->insertOnDuplicate($table, [
                    'attribute_id' => (int) $attribute->getId(),
                    'store_id' => $store,
                    $linkField => $link,
                    'value' => $value,
                ], ['value']);
            }
        }
        foreach ($this->indexers() as $code) {
            try {
                $indexer = $this->indexerRegistry->get($code);
            } catch (\InvalidArgumentException $e) {
                continue;
            }
            if (!$indexer->isScheduled()) {
                $indexer->reindexRow($id);
            }
        }
    }

    public function stores(string $field, int $storeId): array
    {
        $attribute = $this->attribute($field);
        return $attribute ? $this->writeStores($attribute, $storeId) : [$storeId];
    }

    protected function attribute(string $code): ?AbstractAttribute
    {
        $attribute = $this->eavConfig->getAttribute($this->entityType(), $code);
        if (!$attribute || !$attribute->getId() || $attribute->isStatic()) {
            return null;
        }
        // Price attributes take their scope from Catalog Price Scope here.
        $attribute->getBackend();
        return $attribute;
    }

    /**
     * Store views a value for this one is written to; the conflict check
     * (stores()) uses the same rule.
     *
     * @return int[]
     */
    private function writeStores(AbstractAttribute $attribute, int $storeId): array
    {
        if ($attribute->isScopeGlobal() || $storeId === 0) {
            return [0];
        }
        if ($attribute->isScopeWebsite()) {
            try {
                $store = $this->storeManager->getStore($storeId);
            } catch (NoSuchEntityException $e) {
                return [$storeId];
            }
            return array_map('intval', $store->getWebsite()->getStoreIds());
        }
        return [$storeId];
    }

    /**
     * @return string|false false: no row for the store view
     */
    private function value(AbstractAttribute $attribute, int $link, int $store): string|false
    {
        $connection = $this->resource->getConnection();
        $value = $connection->fetchRow(
            $connection->select()
                ->from($attribute->getBackendTable(), ['value'])
                ->where('attribute_id = ?', (int) $attribute->getId())
                ->where('store_id = ?', $store)
                ->where($this->linkField() . ' = ?', $link)
        );
        return $value === false ? false : (string) $value['value'];
    }

    private function nullable(string|false $value): ?string
    {
        return $value === false ? null : $value;
    }

    private function linkField(): string
    {
        return $this->metadataPool->getMetadata($this->entityInterface())->getLinkField();
    }

    /** The row the entity's values hang on (entity_id, or row_id on Adobe Commerce), null when it's gone. */
    protected function linkId(int $id): ?int
    {
        $metadata = $this->metadataPool->getMetadata($this->entityInterface());
        $connection = $this->resource->getConnection();
        $link = $connection->fetchOne(
            $connection->select()
                ->from($this->resource->getTableName($metadata->getEntityTable()), [$metadata->getLinkField()])
                ->where($metadata->getIdentifierField() . ' = ?', $id)
                ->order($metadata->getLinkField() . ' DESC')
                ->limit(1)
        );
        return $link === false ? null : (int) $link;
    }
}
