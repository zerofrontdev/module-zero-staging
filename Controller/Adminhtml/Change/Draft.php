<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\Staging\Controller\Adminhtml\Change;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Zero\Cms\Model\Content\Validator;
use Zero\Staging\Controller\Adminhtml\AbstractAction;
use Zero\Staging\Model\Drafts;

/**
 * The content editor's "Schedule…": keeps what the editor holds (not saved
 * to the page) and answers with the change form to put it into a campaign.
 */
class Draft extends AbstractAction implements HttpPostActionInterface
{
    public function __construct(
        Context $context,
        private readonly Drafts $drafts,
        private readonly Validator $validator
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $content = json_decode((string) $this->getRequest()->getParam('content', ''), true);
        if (!is_array($content)) {
            return $this->json(['error' => (string) __('Nothing to schedule.')], 400);
        }
        $key = $this->drafts->save($this->validator->clean($content));
        return $this->json(['redirect' => $this->getUrl('*/*/edit', [
            'target' => (string) $this->getRequest()->getParam('type'),
            'entity' => (int) $this->getRequest()->getParam('id'),
            'store' => (int) $this->getRequest()->getParam('store'),
            'draft' => $key,
        ])]);
    }
}
