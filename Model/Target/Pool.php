<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\Staging\Model\Target;

use Magento\Framework\Exception\LocalizedException;

/**
 * Every kind of entity a campaign can change, by code (di.xml).
 */
class Pool
{
    /**
     * @param array<string, TargetInterface> $targets
     */
    public function __construct(private readonly array $targets = [])
    {
    }

    /**
     * @throws LocalizedException
     */
    public function get(string $code): TargetInterface
    {
        if (!isset($this->targets[$code]) || !$this->targets[$code] instanceof TargetInterface) {
            throw new LocalizedException(__('Campaigns can\'t change "%1".', $code));
        }
        return $this->targets[$code];
    }

    public function has(string $code): bool
    {
        return isset($this->targets[$code]) && $this->targets[$code] instanceof TargetInterface;
    }

    /**
     * @return array<string, TargetInterface>
     */
    public function all(): array
    {
        return array_filter($this->targets, static fn ($target) => $target instanceof TargetInterface);
    }
}
