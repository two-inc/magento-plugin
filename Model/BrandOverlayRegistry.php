<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Two\Gateway\Model;

use Two\Gateway\Api\BrandOverlayRegistryInterface;

class BrandOverlayRegistry implements BrandOverlayRegistryInterface
{
    /** @var array<string, string> */
    private array $overlays;

    /**
     * @param array<string, string> $overlays Map of overlay key => method
     *        code. Overlay packages add entries via DI argument injection.
     */
    public function __construct(array $overlays = [])
    {
        $this->overlays = $overlays;
    }

    public function isOverlayInstalled(): bool
    {
        return $this->overlays !== [];
    }

    public function getOverlays(): array
    {
        return $this->overlays;
    }
}
