<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\Staging\Model;

use Psr\Log\LoggerInterface;
use Zero\Staging\Model\Target\Pool;

/**
 * Puts a campaign live and undoes it.
 *
 * Going live, each change first keeps the values it replaces, then writes
 * its own. Undoing writes the kept values back, but only into fields that
 * still hold the campaign's value: a price someone changed by hand during
 * the campaign stays as they set it (the change says so). One change that
 * fails (its entity deleted, say) doesn't stop the others.
 */
class Applier
{
    public function __construct(
        private readonly Campaigns $campaigns,
        private readonly Pool $targets,
        private readonly Values $values,
        private readonly CacheCleaner $cacheCleaner,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @return array{applied: int, failed: int}
     */
    public function start(int $campaignId, string $now): array
    {
        $campaign = $this->campaigns->get($campaignId);
        if (!$campaign || $campaign['status'] !== Campaigns::SCHEDULED) {
            return ['applied' => 0, 'failed' => 0];
        }
        $result = ['applied' => 0, 'failed' => 0];
        $tags = [];
        foreach ($this->campaigns->changes($campaignId) as $change) {
            if ($change['status'] !== Campaigns::PENDING) {
                continue;
            }
            try {
                $target = $this->targets->get($change['target']);
                $original = $target->read($change['entity_id'], $change['store_id'], array_keys($change['data']));
                $target->write(
                    $change['entity_id'],
                    $change['store_id'],
                    array_map(static fn ($value) => ['value' => $value], $change['data'])
                );
                $this->campaigns->updateChange($change['change_id'], [
                    'status' => Campaigns::APPLIED,
                    'original' => $original,
                    'message' => null,
                ]);
                $tags = array_merge($tags, $target->cacheTags($change['entity_id']));
                $result['applied']++;
            } catch (\Throwable $e) {
                $this->fail($campaign, $change, $e);
                $result['failed']++;
            }
        }
        $this->campaigns->update($campaignId, [
            'status' => $campaign['end_at'] ? Campaigns::ACTIVE : Campaigns::COMPLETED,
            'started_at' => $now,
        ]);
        $this->cacheCleaner->clean($tags);
        return $result;
    }

    /**
     * @return array{reverted: int, kept: int, failed: int}
     */
    public function end(int $campaignId, string $now): array
    {
        $campaign = $this->campaigns->get($campaignId);
        if (!$campaign || $campaign['status'] !== Campaigns::ACTIVE) {
            return ['reverted' => 0, 'kept' => 0, 'failed' => 0];
        }
        $result = ['reverted' => 0, 'kept' => 0, 'failed' => 0];
        $tags = [];
        foreach ($this->campaigns->changes($campaignId) as $change) {
            if ($change['status'] !== Campaigns::APPLIED || !is_array($change['original'])) {
                continue;
            }
            try {
                $target = $this->targets->get($change['target']);
                $plan = $this->plan($change, $target->fields(), $target->read(
                    $change['entity_id'],
                    $change['store_id'],
                    array_keys($change['data'])
                ));
                if ($plan['restore']) {
                    $target->write($change['entity_id'], $change['store_id'], $plan['restore']);
                    $tags = array_merge($tags, $target->cacheTags($change['entity_id']));
                }
                $this->campaigns->updateChange($change['change_id'], [
                    'status' => Campaigns::REVERTED,
                    'message' => $plan['kept']
                        ? (string) __('Kept as changed during the campaign: %1.', implode(', ', $plan['kept']))
                        : null,
                ]);
                $result['reverted']++;
                $result['kept'] += count($plan['kept']);
            } catch (\Throwable $e) {
                $this->fail($campaign, $change, $e);
                $result['failed']++;
            }
        }
        $this->campaigns->update($campaignId, ['status' => Campaigns::COMPLETED, 'ended_at' => $now]);
        $this->cacheCleaner->clean($tags);
        return $result;
    }

    /**
     * Which fields go back to the values the campaign replaced, and which
     * keep the value someone gave them since.
     *
     * @param array{data: array, original: array} $change
     * @param array<string, array{label: string, type: string}> $fields
     * @param array<string, array{value: mixed, inherited: bool}> $current
     * @return array{restore: array<string, array{value: mixed, inherited?: bool}>, kept: string[]}
     */
    public function plan(array $change, array $fields, array $current): array
    {
        $restore = [];
        $kept = [];
        foreach ($change['data'] as $code => $value) {
            if (!array_key_exists($code, $change['original'])) {
                continue;
            }
            $type = $fields[$code]['type'] ?? 'text';
            if ($this->values->same($current[$code]['value'] ?? null, $value, $type)) {
                $restore[$code] = $change['original'][$code];
            } else {
                $kept[] = (string) ($fields[$code]['label'] ?? $code);
            }
        }
        return ['restore' => $restore, 'kept' => $kept];
    }

    private function fail(array $campaign, array $change, \Throwable $e): void
    {
        $this->campaigns->updateChange($change['change_id'], [
            'status' => Campaigns::FAILED,
            'message' => mb_substr($e->getMessage(), 0, 1000),
        ]);
        $this->logger->warning(sprintf(
            'Zero_Staging: campaign "%s" (%d) couldn\'t change %s %d: %s',
            $campaign['name'],
            $campaign['campaign_id'],
            $change['target'],
            $change['entity_id'],
            $e->getMessage()
        ));
    }
}
