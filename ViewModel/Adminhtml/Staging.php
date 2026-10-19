<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\Staging\ViewModel\Adminhtml;

use Magento\Backend\Model\Session;
use Magento\Backend\Model\UrlInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Data\Form\FormKey;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Store\Model\StoreManagerInterface;
use Zero\Staging\Model\Campaigns;
use Zero\Staging\Model\Clock;
use Zero\Staging\Model\Drafts;
use Zero\Staging\Model\Target\AbstractCms;
use Zero\Staging\Model\Target\Pool;
use Zero\Staging\Model\Target\TargetInterface;
use Zero\Staging\Model\Values;

/**
 * Content > Staging's three pages: the campaigns (with the next weeks on a
 * timeline), a campaign, a change.
 */
class Staging implements ArgumentInterface
{
    public const TIMELINE_DAYS = 28;

    private ?array $campaign = null;
    private ?array $change = null;

    public function __construct(
        private readonly Campaigns $campaigns,
        private readonly Pool $targets,
        private readonly Values $values,
        private readonly Clock $clock,
        private readonly Drafts $drafts,
        private readonly RequestInterface $request,
        private readonly UrlInterface $url,
        private readonly FormKey $formKey,
        private readonly StoreManagerInterface $storeManager,
        private readonly Session $session
    ) {
    }

    public function url(string $route, array $params = []): string
    {
        return $this->url->getUrl($route, $params);
    }

    public function formKey(): string
    {
        return $this->formKey->getFormKey();
    }

    public function format(?string $utc): string
    {
        return $this->clock->format($utc);
    }

    public function zone(): string
    {
        return $this->clock->zone();
    }

    /** Store time now, for a datetime-local field. */
    public function nowInput(): string
    {
        return $this->clock->toInput($this->clock->now());
    }

    public function statusLabel(string $status): string
    {
        return (string) match ($status) {
            Campaigns::SCHEDULED => __('Scheduled'),
            Campaigns::ACTIVE => __('Live'),
            Campaigns::COMPLETED => __('Done'),
            Campaigns::PENDING => __('Waiting'),
            Campaigns::APPLIED => __('Made'),
            Campaigns::REVERTED => __('Undone'),
            Campaigns::FAILED => __('Failed'),
            default => $status,
        };
    }

    /* ---------- the campaigns ---------- */

    /** @return array<int, array> Scheduled and live, soonest first */
    public function upcoming(): array
    {
        return $this->campaigns->all([Campaigns::SCHEDULED, Campaigns::ACTIVE]);
    }

    /** @return array<int, array> Done, latest first */
    public function completed(int $limit = 30): array
    {
        return array_slice($this->campaigns->all([Campaigns::COMPLETED]), 0, $limit);
    }

    /**
     * The next weeks: a column per day, a bar per campaign running in them
     * (left and width in % of the span; open: no end within it).
     *
     * @return array{days: array<int, array{label: string, weekend: bool, today: bool}>, bars: array<int, array>}
     */
    public function timeline(): array
    {
        $first = $this->clock->local($this->clock->now())->setTime(0, 0);
        $from = (clone $first)->setTimezone(new \DateTimeZone('UTC'));
        $span = self::TIMELINE_DAYS * 86400;
        $days = [];
        for ($i = 0; $i < self::TIMELINE_DAYS; $i++) {
            $day = (clone $first)->modify('+' . $i . ' days');
            $days[] = [
                'label' => $day->format('j'),
                'month' => $i === 0 || $day->format('j') === '1' ? $day->format('M') : '',
                'weekend' => (int) $day->format('N') >= 6,
                'today' => $i === 0,
            ];
        }
        $bars = [];
        foreach ($this->upcoming() as $campaign) {
            $start = strtotime($campaign['start_at'] . ' UTC') - $from->getTimestamp();
            $end = $campaign['end_at'] !== null ? strtotime($campaign['end_at'] . ' UTC') - $from->getTimestamp() : null;
            if ($start >= $span || ($end !== null && $end <= 0)) {
                continue;
            }
            $left = max(0, $start);
            $right = $end === null ? $span : min($span, $end);
            $bars[] = $campaign + [
                'left' => round($left / $span * 100, 3),
                'width' => max(0.6, round(($right - $left) / $span * 100, 3)),
                'open' => $end === null || $end > $span,
                'before' => $start < 0,
            ];
        }
        return ['days' => $days, 'bars' => $bars];
    }

    /* ---------- a campaign ---------- */

    public function campaign(): ?array
    {
        if ($this->campaign === null) {
            $id = (int) $this->request->getParam('id');
            $this->campaign = $id ? ($this->campaigns->get($id) ?? []) : [];
        }
        return $this->campaign ?: null;
    }

    /**
     * The form's values: what a failed save sent, else the campaign's.
     *
     * @return array{name: string, description: string, start: string, end: string}
     */
    public function campaignForm(): array
    {
        $posted = (array) $this->session->getData('zero_staging_campaign_form', true);
        $campaign = $this->campaign();
        return [
            'name' => (string) ($posted['name'] ?? $campaign['name'] ?? ''),
            'description' => (string) ($posted['description'] ?? $campaign['description'] ?? ''),
            'start' => (string) ($posted['start'] ?? $this->clock->toInput($campaign['start_at'] ?? null)),
            'end' => (string) ($posted['end'] ?? $this->clock->toInput($campaign['end_at'] ?? null)),
        ];
    }

    /**
     * The campaign's changes, each with its fields as "from → to".
     *
     * @return array<int, array>
     */
    public function changes(): array
    {
        $campaign = $this->campaign();
        if (!$campaign) {
            return [];
        }
        $rows = [];
        foreach ($this->campaigns->changes((int) $campaign['campaign_id']) as $change) {
            $target = $this->targets->has($change['target']) ? $this->targets->get($change['target']) : null;
            $fields = $target ? $target->fields() : [];
            $before = $change['original'] ?? null;
            if ($before === null && $target && $change['status'] === Campaigns::PENDING) {
                try {
                    $before = $target->read($change['entity_id'], $change['store_id'], array_keys($change['data']));
                } catch (\Throwable $e) {
                    $before = [];
                }
            }
            $lines = [];
            foreach ($change['data'] as $code => $value) {
                $field = $fields[$code] ?? ['label' => $code, 'type' => 'text'];
                $lines[] = [
                    'label' => $field['label'],
                    'from' => $this->values->summary($before[$code]['value'] ?? null, $field),
                    'to' => $this->values->summary($value, $field),
                ];
            }
            $rows[] = $change + [
                'kind' => $target ? $target->label() : $change['target'],
                'store' => $target && $target->isStoreScoped() ? $this->storeLabel($change['store_id']) : '',
                'lines' => $lines,
                'edit_url' => $this->url('zero_staging/change/edit', ['change' => $change['change_id']]),
                'entity_url' => $target ? $target->adminUrl($change['entity_id']) : '',
            ];
        }
        return $rows;
    }

    /* ---------- a change ---------- */

    /** The change being edited (?change=), if any. */
    public function change(): ?array
    {
        if ($this->change === null) {
            $id = (int) $this->request->getParam('change');
            $this->change = $id ? ($this->campaigns->change($id) ?? []) : [];
        }
        return $this->change ?: null;
    }

    /**
     * What the change page is about: from the change, else the request.
     *
     * @return array{target: string, entity: int, store: int, campaign: int, draft: string, query: string, banner: int}
     */
    public function subject(): array
    {
        $change = $this->change();
        return [
            'target' => (string) ($change['target'] ?? $this->request->getParam('target', '')),
            'entity' => (int) ($change['entity_id'] ?? $this->request->getParam('entity')),
            'store' => (int) ($change['store_id'] ?? $this->request->getParam('store')),
            'campaign' => (int) ($change['campaign_id'] ?? $this->request->getParam('campaign')),
            'draft' => (string) $this->request->getParam('draft', ''),
            'query' => trim((string) $this->request->getParam('q', '')),
            'banner' => (int) $this->request->getParam('banner'),
        ];
    }

    /** @return array<string, string> code => label */
    public function targetOptions(): array
    {
        return array_map(static fn (TargetInterface $target) => $target->label(), $this->targets->all());
    }

    public function target(): ?TargetInterface
    {
        $code = $this->subject()['target'];
        return $code !== '' && $this->targets->has($code) ? $this->targets->get($code) : null;
    }

    public function entityLabel(): ?string
    {
        $target = $this->target();
        return $target && $this->subject()['entity'] ? $target->describe($this->subject()['entity']) : null;
    }

    /** @return array<int, string> */
    public function searchResults(): array
    {
        $target = $this->target();
        $subject = $this->subject();
        return $target ? $target->search($subject['query'], ['banner' => $subject['banner']]) : [];
    }

    /** @return array<int, string> id => "Website / Store View", 0 first */
    public function stores(): array
    {
        $stores = [0 => (string) __('All Store Views')];
        foreach ($this->storeManager->getStores() as $store) {
            $stores[(int) $store->getId()] = $store->getWebsite()->getName() . ' / ' . $store->getName();
        }
        return $stores;
    }

    /** @return array<int, string> Campaigns a change can go into */
    public function scheduledCampaigns(): array
    {
        $options = [];
        foreach ($this->campaigns->all([Campaigns::SCHEDULED]) as $campaign) {
            $options[(int) $campaign['campaign_id']] = $campaign['name'] . ' — ' . $this->format($campaign['start_at']);
        }
        return $options;
    }

    /**
     * The form's fields: their current values in the store view, the values
     * the campaign's change sets, and whether it sets them.
     *
     * @return array<string, array>
     */
    public function formFields(): array
    {
        $target = $this->target();
        $subject = $this->subject();
        if (!$target || !$subject['entity']) {
            return [];
        }
        $existing = $this->change()
            ?? ($subject['campaign'] ? $this->campaigns->findChange(
                $subject['campaign'],
                $subject['target'],
                $subject['entity'],
                $target->isStoreScoped() ? $subject['store'] : 0
            ) : null);
        $fields = $target->fields();
        try {
            $current = $target->read($subject['entity'], $subject['store'], array_keys($fields));
        } catch (\Throwable $e) {
            $current = [];
        }
        $draft = $subject['draft'] !== '' ? $this->drafts->load($subject['draft']) : null;
        $rows = [];
        foreach ($fields as $code => $field) {
            $set = $existing && array_key_exists($code, $existing['data']);
            $value = $set ? $existing['data'][$code] : ($current[$code]['value'] ?? null);
            if ($field['type'] === 'content' && $draft !== null) {
                $set = true;
                $value = $draft;
            }
            $rows[$code] = $field + [
                'code' => $code,
                'checked' => $set,
                'value' => is_array($value) ? $value : (string) $value,
                'current' => $this->values->summary($current[$code]['value'] ?? null, $field),
                'inherited' => !empty($current[$code]['inherited']),
            ];
        }
        return $rows;
    }

    /**
     * The content editor for this entity: on the scheduled change (its Save
     * goes into the change), or the page's own (where "Schedule…" starts one).
     */
    public function editorUrl(): string
    {
        $subject = $this->subject();
        $params = ['type' => $subject['target'], 'id' => $subject['entity'], 'store' => $subject['store']];
        $change = $this->change();
        if ($change && array_key_exists(AbstractCms::CONTENT, $change['data'])) {
            $params['staging_change'] = $change['change_id'];
        }
        return $this->url('zero_cms/editor/index', $params);
    }

    public function hasDraft(): bool
    {
        $draft = $this->subject()['draft'];
        return $draft !== '' && $this->drafts->load($draft) !== null;
    }

    private function storeLabel(int $storeId): string
    {
        return $this->stores()[$storeId] ?? (string) $storeId;
    }
}
