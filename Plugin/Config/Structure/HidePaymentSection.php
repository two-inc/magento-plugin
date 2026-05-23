<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Two\Gateway\Plugin\Config\Structure;

use Magento\Config\Model\Config\Structure\Element\Section;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Two\Gateway\Api\BrandOverlayRegistryInterface;

/**
 * Hide every Two_Gateway admin config section (`two_general`,
 * `two_payment`, `two_search`) when:
 *   - At least one brand overlay (e.g. ABN_Gateway) is registered, AND
 *   - `payment/two_payment/hide_when_overlay_installed` resolves to truthy.
 *
 * Both conditions default to true on overlay-installed merchants
 * (registry populated by overlay DI, config default = 1). Merchants
 * who want the parent-brand admin surfaces back can opt out with:
 *
 *     bin/magento config:set payment/two_payment/hide_when_overlay_installed 0
 *     bin/magento cache:flush
 *
 * Plugs into `Section::isVisible()` rather than `Structure::getElement()`.
 * The sidebar render path iterates `Structure::getTabs()` and per-tab
 * children, then calls `isVisible()` on each section to decide whether
 * to render it. The Edit controller (`Magento\Config\Controller\Adminhtml\System\Config\Edit`)
 * also calls `isVisible()` after `getElement()` for direct URL access,
 * and the previous `afterGetElement` hook returning null broke that
 * code path with `Call to a member function isVisible() on null`.
 *
 * Returning false from `isVisible()` is the canonical "hide me" signal:
 * the sidebar skips the section and the Edit controller treats it as
 * unauthorised, redirecting away cleanly.
 *
 * `two_general` carries Two-only fields (API key, environment, debug
 * toggle). `two_search` is the Two-side company-search admin. Both
 * are irrelevant to an overlay merchant who configures their own
 * brand under a separate admin section.
 */
class HidePaymentSection
{
    private const HIDE_FLAG_PATH = 'payment/two_payment/hide_when_overlay_installed';
    private const TARGET_SECTIONS = ['two_general', 'two_payment', 'two_search'];

    public function __construct(
        private readonly BrandOverlayRegistryInterface $overlayRegistry,
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    /**
     * @param Section $subject
     * @param bool    $result Whatever Section::isVisible computed natively.
     * @return bool false if the section should be hidden by overlay, $result otherwise.
     */
    public function afterIsVisible(Section $subject, $result)
    {
        if (!$result) {
            return $result;
        }
        if (!in_array($subject->getId(), self::TARGET_SECTIONS, true)) {
            return $result;
        }
        if (!$this->shouldHide()) {
            return $result;
        }
        return false;
    }

    private function shouldHide(): bool
    {
        if (!$this->overlayRegistry->isOverlayInstalled()) {
            return false;
        }
        return (bool)$this->scopeConfig->getValue(self::HIDE_FLAG_PATH);
    }
}
