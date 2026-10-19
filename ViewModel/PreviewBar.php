<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\Staging\ViewModel;

use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Zero\Staging\Model\Campaigns;
use Zero\Staging\Model\Preview\State;

/**
 * The bar over a preview: which moment, from which campaign, and the way out.
 */
class PreviewBar implements ArgumentInterface
{
    public function __construct(
        private readonly State $state,
        private readonly Campaigns $campaigns,
        private readonly TimezoneInterface $timezone,
        private readonly UrlInterface $url
    ) {
    }

    public function isActive(): bool
    {
        return $this->state->isActive();
    }

    /** "27 Nov 2026, 00:00" in the store's time. */
    public function moment(): string
    {
        return $this->timezone->date(new \DateTime($this->state->at(), new \DateTimeZone('UTC')))->format('j M Y, H:i');
    }

    public function campaign(): string
    {
        $campaign = $this->state->campaignId() ? $this->campaigns->get($this->state->campaignId()) : null;
        return $campaign ? (string) $campaign['name'] : '';
    }

    public function stopUrl(): string
    {
        return $this->url->getUrl('zero_staging/preview/stop');
    }
}
