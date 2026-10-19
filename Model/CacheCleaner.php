<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\Staging\Model;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\DataObject\IdentityInterface;
use Magento\Framework\Event\ManagerInterface as EventManager;

/**
 * Cleans what shows the changed entities, as core does after a save: the
 * page cache (built-in or Varnish, through clean_cache_by_tags) and the
 * other caches by the same tags.
 */
class CacheCleaner
{
    public function __construct(
        private readonly CacheInterface $cache,
        private readonly EventManager $eventManager
    ) {
    }

    /**
     * @param string[] $tags
     */
    public function clean(array $tags): void
    {
        $tags = array_values(array_unique(array_filter($tags)));
        if (!$tags) {
            return;
        }
        $object = new class ($tags) implements IdentityInterface {
            public function __construct(private readonly array $tags)
            {
            }

            public function getIdentities()
            {
                return $this->tags;
            }
        };
        $this->eventManager->dispatch('clean_cache_by_tags', ['object' => $object]);
        $this->cache->clean($tags);
    }
}
