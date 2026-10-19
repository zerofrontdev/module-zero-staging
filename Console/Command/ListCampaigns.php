<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\Staging\Console\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Zero\Staging\Model\Campaigns;

/**
 * bin/magento zero:staging:list — every campaign, its times (UTC) and changes.
 */
class ListCampaigns extends Command
{
    public function __construct(private readonly Campaigns $campaigns)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('zero:staging:list')->setDescription('Lists the staging campaigns (times in UTC)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $table = new Table($output);
        $table->setHeaders(['ID', 'Status', 'Name', 'Starts (UTC)', 'Ends (UTC)', 'Changes', 'Failed']);
        foreach ($this->campaigns->all() as $campaign) {
            $table->addRow([
                $campaign['campaign_id'],
                $campaign['status'],
                $campaign['name'],
                $campaign['start_at'],
                $campaign['end_at'] ?? '—',
                $campaign['changes'],
                (int) $campaign['failed'],
            ]);
        }
        $table->render();
        return Command::SUCCESS;
    }
}
