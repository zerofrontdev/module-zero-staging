<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\Staging\Model\Preview;

use Magento\Framework\App\Http\Context as HttpContext;
use Magento\Framework\Stdlib\Cookie\CookieMetadataFactory;
use Magento\Framework\Stdlib\CookieManagerInterface;
use Zero\Staging\Model\Campaigns;

/**
 * Whether this storefront request is a preview, and of which moment.
 *
 * A preview is an HTTP context value (as the customer group is): its pages
 * get their own X-Magento-Vary, so neither the page cache nor Varnish mixes
 * them with the store's; and they aren't cached at all (Plugin\Preview\
 * LayoutCacheable), so an edit to a campaign shows on the next page. The
 * value carries the nonce and the campaigns' last update.
 */
class State
{
    public const COOKIE = 'zero_staging_preview';
    public const CONTEXT = 'zero_staging_preview';

    private ?array $payload = null;
    private bool $loaded = false;
    private ?string $key = null;

    public function __construct(
        private readonly CookieManagerInterface $cookieManager,
        private readonly CookieMetadataFactory $cookieMetadataFactory,
        private readonly HttpContext $httpContext,
        private readonly Link $link,
        private readonly Campaigns $campaigns
    ) {
    }

    public function isActive(): bool
    {
        return $this->payload() !== null;
    }

    /** The moment shown, UTC "Y-m-d H:i:s". */
    public function at(): string
    {
        return gmdate('Y-m-d H:i:s', (int) ($this->payload()['at'] ?? time()));
    }

    public function campaignId(): int
    {
        return (int) ($this->payload()['campaign'] ?? 0);
    }

    /**
     * Starts a preview from the admin's link (its signed value).
     */
    public function start(string $value): bool
    {
        $payload = $this->link->decode($value);
        if ($payload === null) {
            return false;
        }
        $metadata = $this->cookieMetadataFactory->createPublicCookieMetadata()
            ->setPath('/')
            ->setHttpOnly(true)
            ->setDuration(max(60, $payload['expires'] - time()))
            ->setSameSite('Lax');
        $this->cookieManager->setPublicCookie(self::COOKIE, $value, $metadata);
        $this->payload = $payload;
        $this->loaded = true;
        $this->key = null;
        $this->applyContext();
        return true;
    }

    public function stop(): void
    {
        $this->cookieManager->deleteCookie(
            self::COOKIE,
            $this->cookieMetadataFactory->createCookieMetadata()->setPath('/')
        );
        $this->payload = null;
        $this->loaded = true;
        $this->httpContext->unsValue(self::CONTEXT);
    }

    /** Sets the preview's context value (each request, before the page is built). */
    public function applyContext(): void
    {
        if ($this->payload() !== null) {
            $this->httpContext->setValue(self::CONTEXT, $this->key(), '');
        }
    }

    /**
     * What tells this preview's cached things from the store's and from an
     * older state of the campaigns: its nonce, its moment, their last update.
     */
    public function key(): string
    {
        $payload = $this->payload();
        if ($payload === null) {
            return '';
        }
        return $this->key ??= sha1($payload['nonce'] . '|' . $payload['at'] . '|' . $this->campaigns->lastUpdate());
    }

    private function payload(): ?array
    {
        if (!$this->loaded) {
            $this->loaded = true;
            $value = (string) $this->cookieManager->getCookie(self::COOKIE, '');
            $this->payload = $value !== '' ? $this->link->decode($value) : null;
        }
        return $this->payload;
    }
}
