<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\Staging\Cron;

use Zero\Staging\Model\Runner;

/**
 * Every minute: campaigns that start or end now (Model\Runner).
 */
class Run
{
    public function __construct(private readonly Runner $runner)
    {
    }

    public function execute(): void
    {
        $this->runner->run();
    }
}
