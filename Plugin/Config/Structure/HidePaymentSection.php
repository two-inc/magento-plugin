<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Two\Gateway\Plugin\Config\Structure;

use Magento\Config\Model\Config\Structure;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Two\Gateway\Api\BrandOverlayRegistryInterface;

/**
 * Hide the `two_payment` admin config section when:
 *   - At least one brand overlay (e.g. ABN_Gateway) is registered, AND
 *   - `payment/two_payment/hide_when_overlay_installed` resolves to truthy.
 *
 * Both conditions default to true on overlay-installed merchants
 * (registry populated by overlay DI, config default = 1). Merchants
 * who want both parent-brand and overlay-brand payment methods
 * configurable in admin can opt out with:
 *
 *     bin/magento config:set payment/two_payment/hide_when_overlay_installed 0
 *     bin/magento cache:flush
 *
 * Returning null from afterGetElement makes the section invisible to
 * the admin config UI: the left-nav stops listing it, and direct
 * URL access (?section=two_payment) renders empty since `Structure`
 * is the authoritative element source. Returning null does not
 * delete any persisted CCD value; the overlay's `etc/config.xml`
 * still defaults `payment/two_payment/active = 0` as belt-and-braces.
 */
class HidePaymentSection
{
    private const HIDE_FLAG_PATH = 'payment/two_payment/hide_when_overlay_installed';
    private const TARGET_SECTION = 'two_payment';

    public function __construct(
        private readonly BrandOverlayRegistryInterface $overlayRegistry,
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    /**
     * @param Structure $subject
     * @param mixed     $result Whatever Structure::getElement returned.
     * @param string    $path   Section/group path being resolved.
     * @return mixed Null if the section should be hidden, original $result otherwise.
     */
    public function afterGetElement(Structure $subject, $result, $path)
    {
        if (!$this->matchesTarget($path)) {
            return $result;
        }
        if (!$this->shouldHide()) {
            return $result;
        }
        return null;
    }

    private function matchesTarget(string $path): bool
    {
        return $path === self::TARGET_SECTION
            || str_starts_with($path, self::TARGET_SECTION . '/');
    }

    private function shouldHide(): bool
    {
        if (!$this->overlayRegistry->isOverlayInstalled()) {
            return false;
        }
        return (bool)$this->scopeConfig->getValue(self::HIDE_FLAG_PATH);
    }
}
