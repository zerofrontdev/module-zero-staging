<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\Staging\Plugin\Preview;

use Magento\Framework\View\Layout;
use Zero\Staging\Model\Preview\State;

/**
 * Preview pages are never cached (the page cache nor Varnish): an edit to a
 * campaign shows on the next page, and nobody's preview is kept around.
 */
class LayoutCacheable
{
    public function __construct(private readonly State $state)
    {
    }

    public function afterIsCacheable(Layout $subject, bool $result): bool
    {
        return $result && !$this->state->isActive();
    }
}
