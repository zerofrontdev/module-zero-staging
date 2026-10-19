<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\Staging\Model\Preview;

use Zero\Staging\Model\Campaigns;

/**
 * The values a preview shows instead of the store's: what the store will
 * hold at the previewed moment.
 *
 * From the live store, that is: every scheduled campaign running at that
 * moment adds its values (a later start wins, though campaigns can't
 * overlap on a field anyway), and every live campaign that will have ended
 * by then gives back the values it replaced. A campaign that starts and ends
 * before the moment changes nothing; a live one still running is already
 * in the store.
 */
class Overlay
{
    /** @var array<string, array<int, array<int, array<string, mixed>>>>|null target => id => store => values */
    private ?array $index = null;

    public function __construct(
        private readonly Campaigns $campaigns,
        private readonly State $state
    ) {
    }

    /**
     * A store view's values for the entity (All Store Views' first, its own over them).
     *
     * @return array<string, mixed>
     */
    public function values(string $target, int $id, int $storeId): array
    {
        $byStore = $this->index()[$target][$id] ?? [];
        $values = $byStore[0] ?? [];
        if ($storeId !== 0 && isset($byStore[$storeId])) {
            $values = array_replace($values, $byStore[$storeId]);
        }
        return $values;
    }

    /**
     * Entities of a kind the preview changes.
     *
     * @return int[]
     */
    public function ids(string $target): array
    {
        return array_keys($this->index()[$target] ?? []);
    }

    /**
     * @param array<int, array> $changes Campaigns::pendingChanges()
     * @return array<string, array<int, array<int, array<string, mixed>>>>
     */
    public function build(array $changes, string $at): array
    {
        $index = [];
        foreach ($changes as $change) {
            $start = (string) $change['start_at'];
            $end = $change['end_at'] !== null ? (string) $change['end_at'] : null;
            $values = null;
            if ($change['campaign_status'] === Campaigns::SCHEDULED
                && $start <= $at && ($end === null || $end > $at)
                && $change['status'] === Campaigns::PENDING
            ) {
                $values = $change['data'];
            } elseif ($change['campaign_status'] === Campaigns::ACTIVE
                && $end !== null && $end <= $at
                && $change['status'] === Campaigns::APPLIED && is_array($change['original'])
            ) {
                $values = array_map(static fn ($entry) => $entry['value'] ?? null, $change['original']);
            }
            if ($values === null) {
                continue;
            }
            $slot = &$index[$change['target']][$change['entity_id']][$change['store_id']];
            $slot = array_replace($slot ?? [], $values);
            unset($slot);
        }
        return $index;
    }

    private function index(): array
    {
        if ($this->index === null) {
            $this->index = $this->state->isActive()
                ? $this->build($this->campaigns->pendingChanges(), $this->state->at())
                : [];
        }
        return $this->index;
    }
}
