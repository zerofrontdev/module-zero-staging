<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\Staging\Model;

use Magento\Framework\App\ResourceConnection;

/**
 * Campaigns and their changes, as rows. Times are UTC ("Y-m-d H:i:s");
 * a change's data and original are decoded from JSON.
 *
 * Statuses: a campaign is scheduled, then active once it went live (only
 * when it has an end), then completed (undone, or live for good). A change
 * is pending, then applied, then reverted; failed when its entity couldn't
 * be written (the campaign's other changes still go).
 */
class Campaigns
{
    public const SCHEDULED = 'scheduled';
    public const ACTIVE = 'active';
    public const COMPLETED = 'completed';

    public const PENDING = 'pending';
    public const APPLIED = 'applied';
    public const REVERTED = 'reverted';
    public const FAILED = 'failed';

    private const CAMPAIGN = 'zero_staging_campaign';
    private const CHANGE = 'zero_staging_change';

    public function __construct(private readonly ResourceConnection $resource)
    {
    }

    public function get(int $id): ?array
    {
        $connection = $this->resource->getConnection();
        $row = $connection->fetchRow(
            $connection->select()->from($this->table(self::CAMPAIGN))->where('campaign_id = ?', $id)
        );
        return $row ?: null;
    }

    /**
     * Campaigns with their number of changes, soonest first.
     *
     * @param string[]|null $statuses
     * @return array<int, array>
     */
    public function all(?array $statuses = null): array
    {
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from(['c' => $this->table(self::CAMPAIGN)])
            ->joinLeft(
                ['ch' => $this->table(self::CHANGE)],
                'ch.campaign_id = c.campaign_id',
                ['changes' => new \Zend_Db_Expr('COUNT(ch.change_id)'),
                 'failed' => new \Zend_Db_Expr("SUM(ch.status = '" . self::FAILED . "')")]
            )
            ->group('c.campaign_id')
            ->order($statuses === [self::COMPLETED] ? 'c.start_at DESC' : 'c.start_at ASC');
        if ($statuses !== null) {
            $select->where('c.status IN (?)', $statuses);
        }
        return $connection->fetchAll($select);
    }

    /**
     * @param array{name: string, description?: ?string, start_at: string, end_at?: ?string, created_by?: string} $data
     */
    public function save(array $data, ?int $id = null): int
    {
        $connection = $this->resource->getConnection();
        $row = array_intersect_key($data, array_flip(['name', 'description', 'start_at', 'end_at', 'created_by', 'status']));
        if ($id) {
            unset($row['created_by']);
            $connection->update($this->table(self::CAMPAIGN), $row, ['campaign_id = ?' => $id]);
            return $id;
        }
        $connection->insert($this->table(self::CAMPAIGN), $row + ['status' => self::SCHEDULED]);
        return (int) $connection->lastInsertId($this->table(self::CAMPAIGN));
    }

    public function update(int $id, array $fields): void
    {
        $this->resource->getConnection()->update($this->table(self::CAMPAIGN), $fields, ['campaign_id = ?' => $id]);
    }

    public function delete(int $id): void
    {
        $this->resource->getConnection()->delete($this->table(self::CAMPAIGN), ['campaign_id = ?' => $id]);
    }

    /**
     * @return array<int, array>
     */
    public function changes(int $campaignId): array
    {
        $connection = $this->resource->getConnection();
        $rows = $connection->fetchAll(
            $connection->select()
                ->from($this->table(self::CHANGE))
                ->where('campaign_id = ?', $campaignId)
                ->order(['target', 'label', 'store_id'])
        );
        return array_map([$this, 'decode'], $rows);
    }

    public function change(int $changeId): ?array
    {
        $connection = $this->resource->getConnection();
        $row = $connection->fetchRow(
            $connection->select()->from($this->table(self::CHANGE))->where('change_id = ?', $changeId)
        );
        return $row ? $this->decode($row) : null;
    }

    /**
     * The campaign's change of this entity in this store view, if it has one.
     */
    public function findChange(int $campaignId, string $target, int $entityId, int $storeId): ?array
    {
        $connection = $this->resource->getConnection();
        $row = $connection->fetchRow(
            $connection->select()
                ->from($this->table(self::CHANGE))
                ->where('campaign_id = ?', $campaignId)
                ->where('target = ?', $target)
                ->where('entity_id = ?', $entityId)
                ->where('store_id = ?', $storeId)
        );
        return $row ? $this->decode($row) : null;
    }

    /**
     * Adds a change, or replaces the campaign's change of the same entity
     * in the same store view.
     *
     * @param array{campaign_id: int, target: string, entity_id: int, store_id: int, label: string, data: array} $change
     */
    public function saveChange(array $change): int
    {
        $connection = $this->resource->getConnection();
        $connection->insertOnDuplicate($this->table(self::CHANGE), [
            'campaign_id' => $change['campaign_id'],
            'target' => $change['target'],
            'entity_id' => $change['entity_id'],
            'store_id' => $change['store_id'],
            'label' => mb_substr((string) $change['label'], 0, 255),
            'data' => (string) json_encode($change['data']),
            'status' => self::PENDING,
            'message' => null,
        ], ['label', 'data', 'status', 'message']);
        return (int) $connection->fetchOne(
            $connection->select()
                ->from($this->table(self::CHANGE), ['change_id'])
                ->where('campaign_id = ?', $change['campaign_id'])
                ->where('target = ?', $change['target'])
                ->where('entity_id = ?', $change['entity_id'])
                ->where('store_id = ?', $change['store_id'])
        );
    }

    /**
     * @param array{status?: string, message?: ?string, original?: ?array, data?: array} $fields
     */
    public function updateChange(int $changeId, array $fields): void
    {
        foreach (['original', 'data'] as $json) {
            if (array_key_exists($json, $fields)) {
                $fields[$json] = $fields[$json] === null ? null : (string) json_encode($fields[$json]);
            }
        }
        $this->resource->getConnection()->update($this->table(self::CHANGE), $fields, ['change_id = ?' => $changeId]);
    }

    public function deleteChange(int $changeId): void
    {
        $this->resource->getConnection()->delete($this->table(self::CHANGE), ['change_id = ?' => $changeId]);
    }

    /**
     * What the runner has to do now: campaigns to undo, then to start.
     *
     * @return array{end: int[], start: int[]}
     */
    public function due(string $now): array
    {
        $connection = $this->resource->getConnection();
        $table = $this->table(self::CAMPAIGN);
        return [
            'end' => array_map('intval', $connection->fetchCol(
                $connection->select()->from($table, ['campaign_id'])
                    ->where('status = ?', self::ACTIVE)
                    ->where('end_at IS NOT NULL AND end_at <= ?', $now)
                    ->order('end_at ASC')
            )),
            'start' => array_map('intval', $connection->fetchCol(
                $connection->select()->from($table, ['campaign_id'])
                    ->where('status = ?', self::SCHEDULED)
                    ->where('start_at <= ?', $now)
                    ->order('start_at ASC')
            )),
        ];
    }

    /**
     * Other scheduled or live campaigns that change one of these fields of
     * this entity in this store view while this campaign runs.
     *
     * @param string[] $fields
     * @return string[] Their names
     */
    public function conflicts(
        int $campaignId,
        string $start,
        ?string $end,
        string $target,
        int $entityId,
        int $storeId,
        array $fields
    ): array {
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from(['c' => $this->table(self::CAMPAIGN)], ['name'])
            ->join(['ch' => $this->table(self::CHANGE)], 'ch.campaign_id = c.campaign_id', ['data'])
            ->where('c.campaign_id != ?', $campaignId)
            ->where('c.status IN (?)', [self::SCHEDULED, self::ACTIVE])
            ->where('ch.target = ?', $target)
            ->where('ch.entity_id = ?', $entityId)
            ->where('ch.store_id = ?', $storeId)
            ->where('c.end_at IS NULL OR c.end_at > ?', $start);
        if ($end !== null) {
            $select->where('c.start_at < ?', $end);
        }
        $names = [];
        foreach ($connection->fetchAll($select) as $row) {
            $theirs = array_keys((array) json_decode((string) $row['data'], true));
            if (array_intersect($theirs, $fields)) {
                $names[] = (string) $row['name'];
            }
        }
        return array_values(array_unique($names));
    }

    /**
     * Changes of campaigns that aren't over, with their campaign's times and
     * status: what a preview of a moment works from.
     *
     * @return array<int, array>
     */
    public function pendingChanges(): array
    {
        $connection = $this->resource->getConnection();
        $rows = $connection->fetchAll(
            $connection->select()
                ->from(['ch' => $this->table(self::CHANGE)])
                ->join(
                    ['c' => $this->table(self::CAMPAIGN)],
                    'c.campaign_id = ch.campaign_id',
                    ['campaign_status' => 'status', 'start_at', 'end_at']
                )
                ->where('c.status IN (?)', [self::SCHEDULED, self::ACTIVE])
                ->order('c.start_at ASC')
        );
        return array_map([$this, 'decode'], $rows);
    }

    /** When any campaign or change last changed: previews of an older state don't hang on. */
    public function lastUpdate(): string
    {
        $connection = $this->resource->getConnection();
        return (string) $connection->fetchOne(
            'SELECT GREATEST('
            . '(SELECT COALESCE(MAX(updated_at), 0) FROM ' . $this->table(self::CAMPAIGN) . '), '
            . '(SELECT COALESCE(MAX(updated_at), 0) FROM ' . $this->table(self::CHANGE) . '))'
        );
    }

    private function decode(array $row): array
    {
        $row['change_id'] = (int) ($row['change_id'] ?? 0);
        $row['campaign_id'] = (int) $row['campaign_id'];
        $row['entity_id'] = (int) $row['entity_id'];
        $row['store_id'] = (int) $row['store_id'];
        $row['data'] = (array) json_decode((string) $row['data'], true);
        $row['original'] = $row['original'] !== null ? (array) json_decode((string) $row['original'], true) : null;
        return $row;
    }

    private function table(string $name): string
    {
        return $this->resource->getTableName($name);
    }
}
