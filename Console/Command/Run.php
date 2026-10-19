<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\Staging\Console\Command;

use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Zero\Staging\Model\Runner;

/**
 * bin/magento zero:staging:run — what the cron does each minute, now: ends
 * the campaigns whose end has come, starts those whose start has.
 */
class Run extends Command
{
    public function __construct(
        private readonly Runner $runner,
        private readonly State $state
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('zero:staging:run')
            ->setDescription('Starts and ends the campaigns that are due (what the cron does each minute)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->state->setAreaCode(Area::AREA_CRONTAB);
        } catch (\Magento\Framework\Exception\LocalizedException $e) {
            // Already set.
        }
        $done = $this->runner->run();
        $output->writeln(sprintf(
            'Ended: %s. Started: %s.',
            $done['ended'] ? '#' . implode(', #', $done['ended']) : 'none',
            $done['started'] ? '#' . implode(', #', $done['started']) : 'none'
        ));
        return Command::SUCCESS;
    }
}
