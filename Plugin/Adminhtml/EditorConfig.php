<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\Staging\Plugin\Adminhtml;

use Magento\Backend\Model\UrlInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\AuthorizationInterface;
use Zero\Cms\ViewModel\Adminhtml\Editor;
use Zero\Staging\Model\Campaigns;
use Zero\Staging\Model\Clock;
use Zero\Staging\Model\Target\AbstractCms;

/**
 * The Zero CMS editor and campaigns.
 *
 * On a page or block: a "Schedule…" button next to Save, which puts what
 * the editor holds into a campaign instead of the page.
 *
 * Opened on a scheduled change (?staging_change=): the editor shows the
 * change's content, its Save goes into the change (the page stays as it
 * is), Back leads to the campaign, and the store view is the change's.
 */
class EditorConfig
{
    private const TARGETS = ['cms_page', 'cms_block'];

    public function __construct(
        private readonly RequestInterface $request,
        private readonly UrlInterface $url,
        private readonly AuthorizationInterface $authorization,
        private readonly Campaigns $campaigns,
        private readonly Clock $clock
    ) {
    }

    /**
     * @param array{type: string, id: int, store: int} $target
     */
    public function afterGetConfig(Editor $subject, array $result, array $target): array
    {
        if (!in_array($target['type'], self::TARGETS, true) || !$this->authorization->isAllowed('Zero_Staging::staging')) {
            return $result;
        }
        $change = $this->campaigns->change((int) $this->request->getParam('staging_change'));
        if ($change === null
            || $change['target'] !== $target['type']
            || $change['entity_id'] !== $target['id']
            || $change['store_id'] !== $target['store']
        ) {
            $result['actions'][] = [
                'label' => (string) __('Schedule…'),
                'url' => $this->url->getUrl('zero_staging/change/draft', [
                    'type' => $target['type'],
                    'id' => $target['id'],
                    'store' => $target['store'],
                ]),
            ];
            return $result;
        }
        $campaign = $this->campaigns->get($change['campaign_id']);
        $content = $change['data'][AbstractCms::CONTENT] ?? null;
        if (is_array($content)) {
            $result['content'] = $content;
        }
        $result['state'] = 'own';
        $result['notice'] = (string) __(
            'A scheduled change: Save keeps it in the campaign "%1" (starts %2); the page stays as it is until then.',
            $campaign['name'] ?? '',
            $this->clock->format($campaign['start_at'] ?? null)
        );
        $result['revisions'] = [];
        $result['stores'] = array_values(array_filter(
            (array) $result['stores'],
            static fn (array $store) => (int) $store['id'] === $target['store']
        ));
        $result['urls']['save'] = $this->url->getUrl('zero_staging/change/content', ['change' => $change['change_id']]);
        $result['urls']['back'] = $this->url->getUrl('zero_staging/campaign/edit', ['id' => $change['campaign_id']]);
        unset($result['actions']);
        return $result;
    }
}
