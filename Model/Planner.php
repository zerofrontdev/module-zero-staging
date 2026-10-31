<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\Staging\Model;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Lock\LockManagerInterface;
use Zero\Staging\Model\Target\Pool;

/**
 * What the admin does to campaigns, checked: times that make sense, no two
 * campaigns setting the same field of the same entity at the same time,
 * nothing edited once it went live except its name and its end.
 *
 * Every change runs under the cron's lock (Runner::LOCK), checked inside it:
 * Start now can't race the minute's run into starting a campaign twice, and
 * a campaign or change isn't edited or deleted while the run applies it. A
 * run in progress is waited for a few seconds, then the admin is told to
 * try again.
 */
class Planner
{
    /** Seconds an admin action waits for a cron run in progress. */
    public const LOCK_WAIT = 5;

    public function __construct(
        private readonly Campaigns $campaigns,
        private readonly Pool $targets,
        private readonly Applier $applier,
        private readonly Clock $clock,
        private readonly LockManagerInterface $lockManager
    ) {
    }

    /**
     * @param array{name?: string, description?: string, start?: string, end?: string} $input Times in store time
     * @throws LocalizedException
     */
    public function saveCampaign(array $input, ?int $id, string $admin): int
    {
        return $this->locked(fn (): int => $this->doSaveCampaign($input, $id, $admin));
    }

    /**
     * @throws LocalizedException
     */
    private function doSaveCampaign(array $input, ?int $id, string $admin): int
    {
        $campaign = $id ? $this->campaigns->get($id) : null;
        if ($id && !$campaign) {
            throw new LocalizedException(__('This campaign no longer exists.'));
        }
        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '') {
            throw new LocalizedException(__('Give the campaign a name.'));
        }
        $start = $this->clock->toUtc((string) ($input['start'] ?? ''));
        $end = trim((string) ($input['end'] ?? '')) === '' ? null : $this->clock->toUtc((string) $input['end']);
        if ($start === null) {
            throw new LocalizedException(__('Set when the campaign starts.'));
        }
        if (trim((string) ($input['end'] ?? '')) !== '' && $end === null) {
            throw new LocalizedException(__('The end isn\'t a date and time.'));
        }
        if ($end !== null && $end <= $start) {
            throw new LocalizedException(__('The campaign has to end after it starts.'));
        }
        $data = ['name' => $name, 'description' => trim((string) ($input['description'] ?? '')) ?: null];
        $status = $campaign['status'] ?? Campaigns::SCHEDULED;
        if ($status === Campaigns::COMPLETED) {
            $this->campaigns->update((int) $id, $data);
            return (int) $id;
        }
        if ($status === Campaigns::ACTIVE) {
            // Live: its start is history; only its end can move (not into the past).
            if ($end !== null && $end <= $this->clock->now()) {
                throw new LocalizedException(__('A live campaign can\'t end in the past: use End now.'));
            }
            $this->checkChanges((int) $id, (string) $campaign['start_at'], $end);
            $this->campaigns->update((int) $id, $data + ['end_at' => $end]);
            return (int) $id;
        }
        if ($id) {
            $this->checkChanges($id, $start, $end);
        }
        return $this->campaigns->save($data + ['start_at' => $start, 'end_at' => $end, 'created_by' => $admin], $id);
    }

    /**
     * Adds the change to the campaign, or replaces its change of the same
     * entity and store view.
     *
     * @param array<string, mixed> $data Field => value, already in the field's type
     * @throws LocalizedException
     */
    public function saveChange(int $campaignId, string $target, int $entityId, int $storeId, array $data): int
    {
        return $this->locked(fn (): int => $this->doSaveChange($campaignId, $target, $entityId, $storeId, $data));
    }

    /**
     * @throws LocalizedException
     */
    private function doSaveChange(int $campaignId, string $target, int $entityId, int $storeId, array $data): int
    {
        $campaign = $this->scheduled($campaignId);
        $handler = $this->targets->get($target);
        $label = $handler->describe($entityId);
        if ($label === null) {
            throw new LocalizedException(__('This %1 no longer exists.', mb_strtolower($handler->label())));
        }
        $data = array_intersect_key($data, $handler->fields());
        if (!$data) {
            throw new LocalizedException(__('Choose at least one field to change.'));
        }
        $storeId = $handler->isStoreScoped() ? $storeId : 0;
        $this->checkConflicts($campaign, $target, $entityId, $storeId, array_keys($data));
        return $this->campaigns->saveChange([
            'campaign_id' => $campaignId,
            'target' => $target,
            'entity_id' => $entityId,
            'store_id' => $storeId,
            'label' => $label,
            'data' => $data,
        ]);
    }

    /**
     * @throws LocalizedException
     */
    public function deleteChange(int $changeId): int
    {
        return $this->locked(function () use ($changeId): int {
            $change = $this->campaigns->change($changeId);
            if (!$change) {
                throw new LocalizedException(__('This change no longer exists.'));
            }
            $this->scheduled($change['campaign_id']);
            $this->campaigns->deleteChange($changeId);
            return $change['campaign_id'];
        });
    }

    /**
     * @throws LocalizedException
     */
    public function deleteCampaign(int $id): void
    {
        $this->locked(function () use ($id): void {
            $campaign = $this->campaigns->get($id);
            if (!$campaign) {
                return;
            }
            if ($campaign['status'] === Campaigns::ACTIVE) {
                throw new LocalizedException(__('The campaign is live: end it first.'));
            }
            $this->campaigns->delete($id);
        });
    }

    /**
     * Puts a scheduled campaign live now.
     *
     * @return array{applied: int, failed: int}
     * @throws LocalizedException
     */
    public function startNow(int $id): array
    {
        return $this->locked(function () use ($id): array {
            $campaign = $this->scheduled($id);
            $now = $this->clock->now();
            if ($campaign['end_at'] !== null && $campaign['end_at'] <= $now) {
                throw new LocalizedException(__('The campaign\'s end has passed: move it first.'));
            }
            $this->checkChanges($id, $now, $campaign['end_at']);
            $this->campaigns->update($id, ['start_at' => $now]);
            return $this->applier->start($id, $now);
        });
    }

    /**
     * Undoes a live campaign now.
     *
     * @return array{reverted: int, kept: int, failed: int}
     * @throws LocalizedException
     */
    public function endNow(int $id): array
    {
        return $this->locked(function () use ($id): array {
            $campaign = $this->campaigns->get($id);
            if (!$campaign || $campaign['status'] !== Campaigns::ACTIVE) {
                throw new LocalizedException(__('Only a live campaign with an end can be ended.'));
            }
            $now = $this->clock->now();
            $this->campaigns->update($id, ['end_at' => $now]);
            return $this->applier->end($id, $now);
        });
    }

    /**
     * Runs $work under the cron's lock, released whatever happens. Never
     * nested: the database lock backend keeps only one lock per connection.
     *
     * @throws LocalizedException When a cron run holds it longer than LOCK_WAIT
     */
    private function locked(callable $work): mixed
    {
        if (!$this->lockManager->lock(Runner::LOCK, self::LOCK_WAIT)) {
            throw new LocalizedException(
                __('Campaigns are being started or ended right now. Try again in a minute.')
            );
        }
        try {
            return $work();
        } finally {
            $this->lockManager->unlock(Runner::LOCK);
        }
    }

    /**
     * @throws LocalizedException
     */
    private function scheduled(int $campaignId): array
    {
        $campaign = $this->campaigns->get($campaignId);
        if (!$campaign) {
            throw new LocalizedException(__('This campaign no longer exists.'));
        }
        if ($campaign['status'] !== Campaigns::SCHEDULED) {
            throw new LocalizedException(__('The campaign went live: its changes can\'t be edited any more.'));
        }
        return $campaign;
    }

    /**
     * @throws LocalizedException
     */
    private function checkChanges(int $campaignId, string $start, ?string $end): void
    {
        $campaign = ['campaign_id' => $campaignId, 'start_at' => $start, 'end_at' => $end];
        foreach ($this->campaigns->changes($campaignId) as $change) {
            $this->checkConflicts($campaign, $change['target'], $change['entity_id'], $change['store_id'], array_keys($change['data']));
        }
    }

    /**
     * @param string[] $fields
     * @throws LocalizedException
     */
    private function checkConflicts(array $campaign, string $target, int $entityId, int $storeId, array $fields): void
    {
        $names = $this->campaigns->conflicts(
            (int) $campaign['campaign_id'],
            (string) $campaign['start_at'],
            $campaign['end_at'] !== null ? (string) $campaign['end_at'] : null,
            $target,
            $entityId,
            $storeId,
            $fields
        );
        if ($names) {
            throw new LocalizedException(__(
                '"%1" changes the same fields of this %2 at the same time. Change other fields, or move one of the campaigns.',
                implode('", "', $names),
                mb_strtolower($this->targets->get($target)->label())
            ));
        }
    }
}
