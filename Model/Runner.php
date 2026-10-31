<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\Staging\Model;

use Magento\Framework\Lock\LockManagerInterface;

/**
 * What the cron does every minute (and the admin's Start now / End now):
 * undoes the campaigns whose end has come, then puts live those whose start
 * has come, in time order, so a campaign ending at 10:00 hands its fields
 * back before the next one starting at 10:00 takes them. One run at a time.
 */
class Runner
{
    public const LOCK = 'zero_staging_run';

    public function __construct(
        private readonly Campaigns $campaigns,
        private readonly Applier $applier,
        private readonly LockManagerInterface $lockManager,
        private readonly Clock $clock
    ) {
    }

    /**
     * @return array{started: int[], ended: int[]}
     */
    public function run(?string $now = null): array
    {
        $now ??= $this->clock->now();
        $done = ['started' => [], 'ended' => []];
        if (!$this->lockManager->lock(self::LOCK, 0)) {
            return $done;
        }
        try {
            $due = $this->campaigns->due($now);
            foreach ($due['end'] as $id) {
                $this->applier->end($id, $now);
                $done['ended'][] = $id;
            }
            foreach ($due['start'] as $id) {
                $this->applier->start($id, $now);
                $done['started'][] = $id;
            }
        } finally {
            $this->lockManager->unlock(self::LOCK);
        }
        return $done;
    }
}
