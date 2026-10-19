<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\Staging\Block\Adminhtml;

use Magento\Backend\Model\UrlInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\View\Element\UiComponent\Control\ButtonProviderInterface;

/**
 * "Schedule a Change" on an entity's own form (once it's saved): opens the
 * change form for it, in the store view the form shows. A banner's form
 * opens the choice of its slides. One class, configured per form (di.xml).
 */
class ScheduleButton implements ButtonProviderInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly UrlInterface $url,
        private readonly AuthorizationInterface $authorization,
        private readonly string $target = 'product',
        private readonly string $idParam = 'id',
        private readonly string $filterParam = ''
    ) {
    }

    /**
     * @return array
     */
    public function getButtonData()
    {
        $id = (int) $this->request->getParam($this->idParam);
        if (!$id || !$this->authorization->isAllowed('Zero_Staging::staging')) {
            return [];
        }
        $params = ['target' => $this->target, 'store' => (int) $this->request->getParam('store')];
        $params[$this->filterParam !== '' ? $this->filterParam : 'entity'] = $id;
        return [
            'label' => __('Schedule a Change'),
            'class' => 'zero-staging-schedule',
            'on_click' => sprintf("location.href = '%s';", $this->url->getUrl('zero_staging/change/edit', $params)),
            'sort_order' => 26,
        ];
    }
}
