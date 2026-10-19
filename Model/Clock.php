<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\Staging\Model;

use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;

/**
 * Campaign times: UTC in the database, the store's time zone (Stores >
 * Configuration > General > Locale Options, default scope) in the admin.
 */
class Clock
{
    public function __construct(
        private readonly TimezoneInterface $timezone,
        private readonly DateTime $dateTime
    ) {
    }

    public function now(): string
    {
        return $this->dateTime->gmtDate('Y-m-d H:i:s');
    }

    /**
     * "2026-11-27T00:00" (a datetime-local field) in store time => UTC, or null.
     */
    public function toUtc(string $local): ?string
    {
        $local = trim(str_replace('T', ' ', $local));
        if ($local === '' || !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(:\d{2})?$/', $local)) {
            return null;
        }
        $zone = new \DateTimeZone($this->timezone->getConfigTimezone());
        $time = \DateTime::createFromFormat(strlen($local) === 16 ? 'Y-m-d H:i' : 'Y-m-d H:i:s', $local, $zone);
        return $time ? $time->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s') : null;
    }

    /** UTC => the value of a datetime-local field in store time. */
    public function toInput(?string $utc): string
    {
        return $utc ? $this->local($utc)->format('Y-m-d\TH:i') : '';
    }

    /** UTC => "27 Nov 2026, 00:00" in store time. */
    public function format(?string $utc): string
    {
        return $utc ? $this->local($utc)->format('j M Y, H:i') : '';
    }

    public function zone(): string
    {
        return $this->timezone->getConfigTimezone();
    }

    public function local(string $utc): \DateTime
    {
        return (new \DateTime($utc, new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone($this->zone()));
    }
}
