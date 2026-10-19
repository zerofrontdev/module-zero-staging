<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\Staging\Plugin\Preview;

use Zero\Cms\Model\Content\Repository;
use Zero\Staging\Model\Preview\Overlay;
use Zero\Staging\Model\Target\AbstractCms;

/**
 * A page's or block's Zero CMS content as it will be at the previewed
 * moment. A campaign that empties it brings back the page's own HTML.
 */
class CmsContent
{
    private const TARGETS = [Repository::TYPE_PAGE => 'cms_page', Repository::TYPE_BLOCK => 'cms_block'];

    public function __construct(private readonly Overlay $overlay)
    {
    }

    /**
     * @return array{components: array}|null
     */
    public function aroundGetForStore(Repository $subject, callable $proceed, string $type, int $entityId, int $storeId): ?array
    {
        if (isset(self::TARGETS[$type])) {
            $values = $this->overlay->values(self::TARGETS[$type], $entityId, $storeId);
            if (array_key_exists(AbstractCms::CONTENT, $values)) {
                $content = $values[AbstractCms::CONTENT];
                return is_array($content) ? $content : null;
            }
        }
        return $proceed($type, $entityId, $storeId);
    }
}
