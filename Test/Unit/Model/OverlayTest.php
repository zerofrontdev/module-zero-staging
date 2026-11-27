<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\Staging\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use Zero\Staging\Model\Campaigns;
use Zero\Staging\Model\Preview\Overlay;
use Zero\Staging\Model\Preview\State;

/**
 * A preview of a moment: running scheduled campaigns add their values,
 * live ones that will have ended give theirs back, the rest change nothing.
 */
class OverlayTest extends TestCase
{
    private Overlay $overlay;

    protected function setUp(): void
    {
        $this->overlay = new Overlay($this->createStub(Campaigns::class), $this->createStub(State::class));
    }

    public function testScheduledCampaignRunningAtTheMomentAddsItsValues(): void
    {
        $index = $this->overlay->build([
            $this->change('scheduled', '2026-11-27 00:00:00', '2026-11-30 23:59:00', 'pending', ['price' => 27.0]),
        ], '2026-11-28 12:00:00');

        self::assertSame(['price' => 27.0], $index['product'][5][0]);
    }

    public function testCampaignsOutsideTheMomentChangeNothing(): void
    {
        $index = $this->overlay->build([
            $this->change('scheduled', '2026-12-01 00:00:00', null, 'pending', ['price' => 27.0]),
            $this->change('scheduled', '2026-11-01 00:00:00', '2026-11-02 00:00:00', 'pending', ['name' => 'Gone']),
            $this->change('active', '2026-10-01 00:00:00', '2026-12-31 00:00:00', 'applied', ['name' => 'Live'], ['name' => ['value' => 'Was']]),
        ], '2026-11-28 12:00:00');

        self::assertSame([], $index);
    }

    public function testLiveCampaignEndedByTheMomentGivesItsValuesBack(): void
    {
        $index = $this->overlay->build([
            $this->change('active', '2026-11-01 00:00:00', '2026-11-20 00:00:00', 'applied', ['name' => 'Autumn'], [
                'name' => ['value' => 'Tee', 'inherited' => true],
            ]),
        ], '2026-11-28 12:00:00');

        self::assertSame(['name' => 'Tee'], $index['product'][5][0]);
    }

    private function change(string $status, string $start, ?string $end, string $changeStatus, array $data, ?array $original = null): array
    {
        return [
            'campaign_status' => $status,
            'start_at' => $start,
            'end_at' => $end,
            'status' => $changeStatus,
            'target' => 'product',
            'entity_id' => 5,
            'store_id' => 0,
            'data' => $data,
            'original' => $original,
        ];
    }
}
