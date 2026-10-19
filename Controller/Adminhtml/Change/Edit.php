<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\Staging\Controller\Adminhtml\Change;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Zero\Staging\Controller\Adminhtml\AbstractAction;

/**
 * A change: what to change (a kind, then an entity, found by a search), in
 * which store view, which fields to what, and in which campaign. Opened from
 * a campaign, from an entity's own form ("Schedule a Change"), or from the
 * content editor ("Schedule…").
 */
class Edit extends AbstractAction implements HttpGetActionInterface
{
    public function execute()
    {
        return $this->page((string) __('Scheduled Change'));
    }
}
