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
use Zero\Cms\Model\Content\Validator;
use Zero\Staging\Controller\Adminhtml\AbstractAction;
use Zero\Staging\Model\Campaigns;
use Zero\Staging\Model\Planner;
use Zero\Staging\Model\Target\AbstractCms;

/**
 * The content editor's Save while it edits a scheduled change: the content
 * goes into the change, the page stays as it is. Answers as the editor's
 * own Save does.
 */
class Content extends AbstractAction implements HttpPostActionInterface
{
    public function __construct(
        Context $context,
        private readonly Campaigns $campaigns,
        private readonly Planner $planner,
        private readonly Validator $validator
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $change = $this->campaigns->change((int) $this->getRequest()->getParam('change'));
        $content = json_decode((string) $this->getRequest()->getParam('content', ''), true);
        if (!$change || !is_array($content)) {
            return $this->json(['error' => (string) __('This change no longer exists.')], 400);
        }
        try {
            $clean = $this->validator->clean($content);
            $this->planner->saveChange(
                $change['campaign_id'],
                $change['target'],
                $change['entity_id'],
                $change['store_id'],
                [AbstractCms::CONTENT => $clean] + $change['data']
            );
        } catch (LocalizedException $e) {
            return $this->json(['error' => $e->getMessage()], 400);
        }
        return $this->json(['content' => $clean, 'revisions' => [], 'warnings' => []]);
    }
}
