<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\Staging\Plugin\Preview;

use Magento\Framework\App\ActionInterface;
use Zero\Staging\Model\Preview\State;

/**
 * Every storefront action of a preview: its HTTP context value, before the
 * page is built (as the customer's group is set, Magento_Customer's
 * ContextPlugin), so the response keeps the preview's X-Magento-Vary.
 */
class HttpContext
{
    public function __construct(private readonly State $state)
    {
    }

    public function beforeExecute(ActionInterface $subject): void
    {
        $this->state->applyContext();
    }
}
