<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\Staging\Plugin\Preview;

use Zero\Banner\Block\Widget\Banner;
use Zero\Staging\Model\Preview\Overlay;

/**
 * In a preview, slides with the texts, link and pictures they will have.
 */
class SlideContent
{
    public function __construct(private readonly Overlay $overlay)
    {
    }

    /**
     * @param \Zero\Banner\Model\Slide[] $result
     * @return \Zero\Banner\Model\Slide[]
     */
    public function afterGetSlides(Banner $subject, array $result): array
    {
        foreach ($result as $slide) {
            $values = $this->overlay->values('banner_slide', (int) $slide->getId(), 0);
            unset($values['is_active']);
            if ($values) {
                $slide->addData($values);
            }
        }
        return $result;
    }
}
