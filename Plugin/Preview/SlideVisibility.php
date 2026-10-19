<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\Staging\Plugin\Preview;

use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Zero\Banner\Model\ResourceModel\Slide\Collection;
use Zero\Staging\Model\Preview\Overlay;
use Zero\Staging\Model\Preview\State;

/**
 * In a preview, the slides that show at the previewed moment: their Show
 * From/To dates against that day, and the ones a campaign switches on or off.
 */
class SlideVisibility
{
    public function __construct(
        private readonly State $state,
        private readonly Overlay $overlay,
        private readonly TimezoneInterface $timezone
    ) {
    }

    public function aroundAddVisibleFilter(Collection $subject, callable $proceed, string $date): Collection
    {
        if (!$this->state->isActive()) {
            return $proceed($date);
        }
        $day = $this->timezone->date(new \DateTime($this->state->at(), new \DateTimeZone('UTC')))->format('Y-m-d');
        $on = [];
        $off = [];
        foreach ($this->overlay->ids('banner_slide') as $id) {
            $values = $this->overlay->values('banner_slide', $id, 0);
            if (array_key_exists('is_active', $values)) {
                if ((int) $values['is_active']) {
                    $on[] = $id;
                } else {
                    $off[] = $id;
                }
            }
        }
        $connection = $subject->getConnection();
        $active = 'main_table.is_active = 1'
            . ($off ? ' AND ' . $connection->quoteInto('main_table.slide_id NOT IN (?)', $off) : '');
        $subject->getSelect()
            ->where('(' . $active . ')' . ($on ? ' OR ' . $connection->quoteInto('main_table.slide_id IN (?)', $on) : ''))
            ->where('main_table.date_from IS NULL OR main_table.date_from <= ?', $day)
            ->where('main_table.date_to IS NULL OR main_table.date_to >= ?', $day);
        return $subject;
    }
}
