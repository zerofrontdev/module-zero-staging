<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\Staging\Model;

use Magento\Framework\App\CacheInterface;

/**
 * Content the Zero CMS editor sent with "Schedule…", kept for an hour until
 * the admin picks the campaign it goes into.
 */
class Drafts
{
    private const LIFETIME = 3600;
    private const PREFIX = 'zero_staging_draft_';

    public function __construct(private readonly CacheInterface $cache)
    {
    }

    public function save(array $content): string
    {
        $key = bin2hex(random_bytes(16));
        $this->cache->save((string) json_encode($content), self::PREFIX . $key, [], self::LIFETIME);
        return $key;
    }

    public function load(string $key): ?array
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $key)) {
            return null;
        }
        $json = $this->cache->load(self::PREFIX . $key);
        $content = is_string($json) ? json_decode($json, true) : null;
        return is_array($content) ? $content : null;
    }
}
