<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\Staging\Controller\Adminhtml\Change;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Exception\LocalizedException;
use Zero\Staging\Controller\Adminhtml\AbstractAction;
use Zero\Staging\Model\Campaigns;
use Zero\Staging\Model\Drafts;
use Zero\Staging\Model\Planner;
use Zero\Staging\Model\Target\AbstractCms;
use Zero\Staging\Model\Target\Pool;
use Zero\Staging\Model\Values;

/**
 * Saves a change: the ticked fields with their new values, into the chosen
 * campaign or a new one made from the form. The Zero CMS content comes from
 * the editor (a draft, or what the change already holds).
 */
class Save extends AbstractAction implements HttpPostActionInterface
{
    public function __construct(
        Context $context,
        private readonly Planner $planner,
        private readonly Campaigns $campaigns,
        private readonly Pool $targets,
        private readonly Values $values,
        private readonly Drafts $drafts
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $request = $this->getRequest();
        $target = (string) $request->getParam('target');
        $entityId = (int) $request->getParam('entity');
        $storeId = (int) $request->getParam('store');
        $campaignId = (int) $request->getParam('campaign');
        try {
            $handler = $this->targets->get($target);
            $data = [];
            $ticked = (array) $request->getParam('change', []);
            $input = (array) $request->getParam('value', []);
            foreach ($handler->fields() as $code => $field) {
                if (empty($ticked[$code])) {
                    continue;
                }
                if ($field['type'] === 'content') {
                    $content = $this->content((string) $request->getParam('draft'), $campaignId, $target, $entityId, $storeId);
                    if ($content === null) {
                        throw new LocalizedException(__('Edit the content in the content editor first ("Edit content").'));
                    }
                    $data[$code] = $content;
                    continue;
                }
                $data[$code] = $this->values->fromInput($input[$code] ?? null, $field['type']);
            }
            if (!$campaignId) {
                $campaignId = $this->planner->saveCampaign((array) $request->getParam('new', []), null, $this->adminName());
            }
            $changeId = $this->planner->saveChange($campaignId, $target, $entityId, $storeId, $data);
            $this->messageManager->addSuccessMessage(__('The change is scheduled.'));
            return $this->back('*/campaign/edit', ['id' => $campaignId, '_fragment' => 'change-' . $changeId]);
        } catch (LocalizedException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
            return $this->back('*/*/edit', array_filter([
                'target' => $target,
                'entity' => $entityId,
                'store' => $storeId,
                'campaign' => $campaignId,
                'draft' => (string) $request->getParam('draft'),
            ]));
        }
    }

    /**
     * The content to schedule: the editor's draft, else what the campaign's
     * change of this entity already holds.
     */
    private function content(string $draft, int $campaignId, string $target, int $entityId, int $storeId): ?array
    {
        if ($draft !== '') {
            $content = $this->drafts->load($draft);
            if ($content !== null) {
                return $content;
            }
        }
        $change = $campaignId ? $this->campaigns->findChange($campaignId, $target, $entityId, $storeId) : null;
        $content = $change['data'][AbstractCms::CONTENT] ?? null;
        return is_array($content) ? $content : null;
    }
}
