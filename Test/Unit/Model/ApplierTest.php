<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\Staging\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Zero\Staging\Model\Applier;
use Zero\Staging\Model\CacheCleaner;
use Zero\Staging\Model\Campaigns;
use Zero\Staging\Model\Target\Pool;
use Zero\Staging\Model\Target\TargetInterface;
use Zero\Staging\Model\Values;

/**
 * Undoing a campaign: fields still holding its value go back (with their
 * inheritance), fields someone changed since keep their new value. Going
 * live: the values a change replaces are stored on it while it is still
 * pending, before anything is written, and never read again once stored.
 */
class ApplierTest extends TestCase
{
    private Applier $applier;
    /** @var array<int, array> read, write, updateChange and update calls, in order */
    private array $events = [];
    /** @var array<string, array{value: mixed, inherited: bool}> what the target reads */
    private array $current = ['price' => ['value' => '34.0000', 'inherited' => false], 'name' => ['value' => 'Tee', 'inherited' => true]];
    private ?\Throwable $writeFails = null;

    protected function setUp(): void
    {
        $this->applier = new Applier(
            $this->createStub(Campaigns::class),
            new Pool([]),
            new Values(),
            $this->createStub(CacheCleaner::class),
            new NullLogger()
        );
    }

    public function testRestoresFieldsStillHoldingTheCampaignsValue(): void
    {
        $plan = $this->applier->plan(
            [
                'data' => ['price' => 27.0, 'name' => 'Black Friday Tee'],
                'original' => [
                    'price' => ['value' => '34.0000', 'inherited' => false],
                    'name' => ['value' => 'Tee', 'inherited' => true],
                ],
            ],
            ['price' => ['label' => 'Price', 'type' => 'price'], 'name' => ['label' => 'Name', 'type' => 'text']],
            ['price' => ['value' => '27.0000', 'inherited' => false], 'name' => ['value' => 'Black Friday Tee', 'inherited' => false]]
        );

        self::assertSame([
            'price' => ['value' => '34.0000', 'inherited' => false],
            'name' => ['value' => 'Tee', 'inherited' => true],
        ], $plan['restore']);
        self::assertSame([], $plan['kept']);
    }

    public function testKeepsAFieldChangedByHandDuringTheCampaign(): void
    {
        $plan = $this->applier->plan(
            [
                'data' => ['price' => 27.0, 'special_price' => 19.99],
                'original' => [
                    'price' => ['value' => '34.0000', 'inherited' => false],
                    'special_price' => ['value' => null, 'inherited' => false],
                ],
            ],
            ['price' => ['label' => 'Price', 'type' => 'price'], 'special_price' => ['label' => 'Special Price', 'type' => 'price']],
            ['price' => ['value' => '25.0000', 'inherited' => false], 'special_price' => ['value' => '19.9900', 'inherited' => false]]
        );

        self::assertSame(['special_price' => ['value' => null, 'inherited' => false]], $plan['restore']);
        self::assertSame(['Price'], $plan['kept']);
    }

    public function testContentComparesByValueNotKeyOrder(): void
    {
        $values = new Values();
        self::assertTrue($values->same(
            ['components' => [['type' => 'hero', 'data' => ['title' => 'Sale', 'image' => 'a.jpg']]]],
            ['components' => [['data' => ['image' => 'a.jpg', 'title' => 'Sale'], 'type' => 'hero']]],
            'content'
        ));
        self::assertFalse($values->same(
            ['components' => [['type' => 'hero', 'data' => ['title' => 'Sale']]]],
            ['components' => [['type' => 'hero', 'data' => ['title' => 'Spring']]]],
            'content'
        ));
        self::assertTrue($values->same('27', 27.0, 'price'));
        self::assertTrue($values->same(null, '', 'text'));
    }

    /**
     * An Applier over one scheduled campaign with this change, the target and Campaigns recording into $events.
     */
    private function applierFor(array $change): Applier
    {
        $campaigns = $this->createStub(Campaigns::class);
        $campaigns->method('get')->willReturn(['campaign_id' => 3, 'name' => 'Black Friday', 'status' => Campaigns::SCHEDULED, 'end_at' => '2026-11-30 00:00:00']);
        $campaigns->method('changes')->willReturn([$change + [
            'change_id' => 8, 'campaign_id' => 3, 'target' => 'product', 'entity_id' => 42, 'store_id' => 1, 'status' => Campaigns::PENDING, 'original' => null,
        ]]);
        $campaigns->method('updateChange')->willReturnCallback(function (int $id, array $fields) {
            $this->events[] = ['updateChange', $id, $fields];
        });
        $campaigns->method('update')->willReturnCallback(function (int $id, array $fields) {
            $this->events[] = ['update', $id, $fields];
        });
        $target = $this->createStub(TargetInterface::class);
        $target->method('read')->willReturnCallback(function (int $id, int $storeId, array $fields) {
            $this->events[] = ['read', $id, $storeId, $fields];
            return array_intersect_key($this->current, array_flip($fields));
        });
        $target->method('write')->willReturnCallback(function (int $id, int $storeId, array $values) {
            $this->events[] = ['write', $id, $storeId, $values];
            if ($this->writeFails) {
                throw $this->writeFails;
            }
        });
        $target->method('cacheTags')->willReturn(['cat_p_42']);
        return new Applier($campaigns, new Pool(['product' => $target]), new Values(), $this->createStub(CacheCleaner::class), new NullLogger());
    }

    public function testTheOriginalIsKeptBeforeTheWrite(): void
    {
        $result = $this->applierFor(['data' => ['price' => 27.0]])->start(3, '2026-11-27 00:00:00');

        $price = ['price' => ['value' => '34.0000', 'inherited' => false]];
        self::assertSame(['applied' => 1, 'failed' => 0], $result);
        self::assertSame([
            ['read', 42, 1, ['price']],
            ['updateChange', 8, ['original' => $price]],
            ['write', 42, 1, ['price' => ['value' => 27.0]]],
            ['updateChange', 8, ['status' => Campaigns::APPLIED, 'original' => $price, 'message' => null]],
            ['update', 3, ['status' => Campaigns::ACTIVE, 'started_at' => '2026-11-27 00:00:00']],
        ], $this->events);
    }

    public function testAWriteThatFailsKeepsTheOriginal(): void
    {
        $this->writeFails = new \RuntimeException('Product 42 no longer exists.');
        $result = $this->applierFor(['data' => ['price' => 27.0]])->start(3, '2026-11-27 00:00:00');

        self::assertSame(['applied' => 0, 'failed' => 1], $result);
        $changes = array_values(array_filter($this->events, static fn (array $e) => $e[0] === 'updateChange'));
        self::assertSame(['original' => ['price' => ['value' => '34.0000', 'inherited' => false]]], $changes[0][2], 'stored while still pending');
        self::assertSame(['status' => Campaigns::FAILED, 'message' => 'Product 42 no longer exists.'], $changes[1][2], 'the failure leaves the original alone');
        self::assertCount(2, $changes);
    }

    public function testAStoredOriginalIsNotReadAgain(): void
    {
        // A restart after a crash: the change is still pending but knows what it replaces; the store holds the campaign's value.
        $this->current['price'] = ['value' => '27.0000', 'inherited' => false];
        $stored = ['price' => ['value' => '34.0000', 'inherited' => false]];
        $this->applierFor(['data' => ['price' => 27.0], 'original' => $stored])->start(3, '2026-11-27 00:00:00');

        self::assertSame([], array_values(array_filter($this->events, static fn (array $e) => $e[0] === 'read')));
        self::assertSame(['write', 42, 1, ['price' => ['value' => 27.0]]], $this->events[0]);
        self::assertSame(['updateChange', 8, ['status' => Campaigns::APPLIED, 'original' => $stored, 'message' => null]], $this->events[1]);
    }

    public function testOnlyMissingFieldsAreRead(): void
    {
        $stored = ['price' => ['value' => '34.0000', 'inherited' => false]];
        $this->applierFor(['data' => ['price' => 27.0, 'name' => 'Black Friday Tee'], 'original' => $stored])->start(3, '2026-11-27 00:00:00');

        self::assertSame(['read', 42, 1, ['name']], $this->events[0]);
        self::assertSame(['updateChange', 8, ['original' => $stored + ['name' => ['value' => 'Tee', 'inherited' => true]]]], $this->events[1]);
        self::assertSame('write', $this->events[2][0]);
    }

    public function testNothingIsStoredWhenTheReadFindsNothing(): void
    {
        $this->current = [];
        $this->applierFor(['data' => ['price' => 27.0]])->start(3, '2026-11-27 00:00:00');

        self::assertSame(['read', 'write', 'updateChange', 'update'], array_column($this->events, 0));
        self::assertSame(['status' => Campaigns::APPLIED, 'original' => [], 'message' => null], $this->events[2][2]);
    }
}
