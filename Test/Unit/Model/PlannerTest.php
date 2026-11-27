<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\Staging\Test\Unit\Model;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Lock\LockManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zero\Staging\Model\Applier;
use Zero\Staging\Model\Campaigns;
use Zero\Staging\Model\Clock;
use Zero\Staging\Model\Planner;
use Zero\Staging\Model\Runner;
use Zero\Staging\Model\Target\Pool;
use Zero\Staging\Model\Target\TargetInterface;

/**
 * Every admin change to campaigns runs under the cron's lock (waiting a few
 * seconds), checked inside it: Start now can't start a campaign the minute's
 * run started meanwhile, nothing is read or written while a run holds the
 * lock (the admin is told to try again), and the lock is let go whatever
 * happens.
 */
class PlannerTest extends TestCase
{
    /** @var string[] lock, unlock and every Campaigns / Applier call, in order */
    public array $log = [];
    public bool $free = true;
    /** @var array<string, mixed>|null what Campaigns::get() returns */
    private ?array $campaign = null;
    /** @var \Throwable|null what Applier::start() throws */
    private ?\Throwable $startFails = null;

    private function planner(): Planner
    {
        $lock = new class ($this) implements LockManagerInterface {
            public function __construct(private readonly PlannerTest $test)
            {
            }

            public function lock(string $name, int $timeout = -1): bool
            {
                $this->test->log[] = 'lock ' . $name . ' ' . $timeout;
                return $this->test->free;
            }

            public function unlock(string $name): bool
            {
                $this->test->log[] = 'unlock ' . $name;
                return true;
            }

            public function isLocked(string $name): bool
            {
                return !$this->test->free;
            }
        };
        $campaigns = $this->createStub(Campaigns::class);
        $campaigns->method('get')->willReturnCallback(function (int $id) {
            $this->log[] = 'get ' . $id;
            return $this->campaign;
        });
        $campaigns->method('change')->willReturnCallback(function (int $id) {
            $this->log[] = 'change ' . $id;
            return ['change_id' => $id, 'campaign_id' => 3];
        });
        $campaigns->method('changes')->willReturnCallback(function () {
            $this->log[] = 'changes';
            return [];
        });
        $campaigns->method('conflicts')->willReturnCallback(function () {
            $this->log[] = 'conflicts';
            return [];
        });
        $campaigns->method('update')->willReturnCallback(function (int $id, array $fields) {
            $this->log[] = 'update ' . $id . ' ' . implode(',', array_keys($fields));
        });
        $campaigns->method('save')->willReturnCallback(function () {
            $this->log[] = 'save';
            return 3;
        });
        $campaigns->method('delete')->willReturnCallback(function (int $id) {
            $this->log[] = 'delete ' . $id;
        });
        $campaigns->method('saveChange')->willReturnCallback(function () {
            $this->log[] = 'saveChange';
            return 11;
        });
        $campaigns->method('deleteChange')->willReturnCallback(function (int $id) {
            $this->log[] = 'deleteChange ' . $id;
        });
        $applier = $this->createStub(Applier::class);
        $applier->method('start')->willReturnCallback(function (int $id, string $now) {
            $this->log[] = 'start ' . $id . ' ' . $now;
            if ($this->startFails) {
                throw $this->startFails;
            }
            return ['applied' => 2, 'failed' => 0];
        });
        $applier->method('end')->willReturnCallback(function (int $id, string $now) {
            $this->log[] = 'end ' . $id . ' ' . $now;
            return ['reverted' => 2, 'kept' => 0, 'failed' => 0];
        });
        $clock = $this->createStub(Clock::class);
        $clock->method('now')->willReturn('2026-10-29 10:00:00');
        $clock->method('toUtc')->willReturnCallback(static fn (string $local) => $local === '' ? null : str_replace('T', ' ', $local) . ':00');
        $product = $this->createStub(TargetInterface::class);
        $product->method('label')->willReturn('Product');
        $product->method('describe')->willReturn('Affirm Water Bottle (24-UG06)');
        $product->method('fields')->willReturn(['price' => ['label' => 'Price', 'type' => 'price']]);
        $product->method('isStoreScoped')->willReturn(true);
        return new Planner($campaigns, new Pool(['product' => $product]), $applier, $clock, $lock);
    }

    private function scheduled(array $fields = []): array
    {
        return $fields + ['campaign_id' => 3, 'name' => 'Black Friday', 'status' => Campaigns::SCHEDULED,
            'start_at' => '2026-11-27 00:00:00', 'end_at' => '2026-11-30 00:00:00'];
    }

    public function testStartNowRunsInsideTheRunLock(): void
    {
        $this->campaign = $this->scheduled();
        $result = $this->planner()->startNow(3);

        self::assertSame(['applied' => 2, 'failed' => 0], $result);
        self::assertSame([
            'lock zero_staging_run 5',
            'get 3',
            'changes',
            'update 3 start_at',
            'start 3 2026-10-29 10:00:00',
            'unlock zero_staging_run',
        ], $this->log);
        self::assertSame('zero_staging_run', Runner::LOCK, 'the same lock as the minute\'s run');
    }

    public function testEndNowRunsInsideTheRunLock(): void
    {
        $this->campaign = $this->scheduled(['status' => Campaigns::ACTIVE]);
        self::assertSame(['reverted' => 2, 'kept' => 0, 'failed' => 0], $this->planner()->endNow(3));
        self::assertSame([
            'lock zero_staging_run 5',
            'get 3',
            'update 3 end_at',
            'end 3 2026-10-29 10:00:00',
            'unlock zero_staging_run',
        ], $this->log);
    }

    public function testACampaignStartedMeanwhileIsNotStartedAgain(): void
    {
        // The minute's run took it live while the admin waited for the lock.
        $this->campaign = $this->scheduled(['status' => Campaigns::ACTIVE]);
        try {
            $this->planner()->startNow(3);
            self::fail('Started a live campaign again');
        } catch (LocalizedException $e) {
            self::assertSame('The campaign went live: its changes can\'t be edited any more.', $e->getMessage());
        }
        self::assertSame(['lock zero_staging_run 5', 'get 3', 'unlock zero_staging_run'], $this->log);
    }

    public function testTheLockIsReleasedWhenStartFails(): void
    {
        $this->campaign = $this->scheduled();
        $this->startFails = new \RuntimeException('Deadlock found when trying to get lock');
        try {
            $this->planner()->startNow(3);
            self::fail('No exception');
        } catch (\RuntimeException $e) {
            self::assertSame('Deadlock found when trying to get lock', $e->getMessage());
        }
        self::assertSame('unlock zero_staging_run', end($this->log));
        self::assertContains('start 3 2026-10-29 10:00:00', $this->log);
    }

    /**
     * @return array<string, array{0: callable(Planner): mixed, 1: string}>
     */
    public static function mutators(): array
    {
        return [
            'save a campaign' => [static fn (Planner $p) => $p->saveCampaign(['name' => 'Black Friday', 'start' => '2026-11-27T00:00', 'end' => '2026-11-30T00:00'], 3, 'admin'), 'save'],
            'save a change' => [static fn (Planner $p) => $p->saveChange(3, 'product', 42, 1, ['price' => 27.0]), 'saveChange'],
            'delete a change' => [static fn (Planner $p) => $p->deleteChange(11), 'deleteChange 11'],
            'delete a campaign' => [static fn (Planner $p) => $p->deleteCampaign(3), 'delete 3'],
            'start now' => [static fn (Planner $p) => $p->startNow(3), 'start 3 2026-10-29 10:00:00'],
            'end now' => [static fn (Planner $p) => $p->endNow(3), 'end 3 2026-10-29 10:00:00'],
        ];
    }

    #[DataProvider('mutators')]
    public function testEveryChangeRunsInsideTheRunLock(callable $call, string $write): void
    {
        $this->campaign = $this->scheduled($write === 'end 3 2026-10-29 10:00:00' ? ['status' => Campaigns::ACTIVE] : []);
        $call($this->planner());

        self::assertSame('lock zero_staging_run 5', $this->log[0]);
        self::assertSame('unlock zero_staging_run', end($this->log));
        self::assertContains($write, $this->log);
        self::assertCount(1, array_filter($this->log, static fn (string $l) => str_starts_with($l, 'lock ')), 'never nested');
    }

    #[DataProvider('mutators')]
    public function testWhileARunHoldsTheLockNothingIsReadOrWritten(callable $call, string $write): void
    {
        $this->free = false;
        $this->campaign = $this->scheduled();
        try {
            $call($this->planner());
            self::fail('No exception');
        } catch (LocalizedException $e) {
            self::assertSame('Campaigns are being started or ended right now. Try again in a minute.', $e->getMessage());
        }
        self::assertNotContains($write, $this->log);
        self::assertSame(['lock zero_staging_run 5'], $this->log, 'no read, no write, nothing to unlock');
    }
}
