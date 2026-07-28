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
 * Hide every vanilla Two_Gateway admin config section (`two_general`,
 * `two_payment`, `two_search`, `two_version`) when:
 *   - At least one brand overlay (e.g. Overlay_Gateway) is registered, AND
 *   - `two_brand_synthesis/hide_payment_section/enabled` resolves to truthy.
 *
 * Both conditions default to true on overlay-installed merchants
 * (registry populated by overlay DI, flag default = 1). Merchants
 * who want the parent-brand admin surfaces back can opt out by adding
 * the value to `app/etc/env.php` (or `app/etc/config.php`) and flushing:
 *
 *     'system' => ['default' => ['two_brand_synthesis' =>
 *         ['hide_payment_section' => ['enabled' => 0]]]]
 *
 *     bin/magento cache:flush
 *
 * NOTE: `bin/magento config:set` does NOT work for this path, and never
 * did for its predecessor either — `config:set`/`config:show` validate
 * the path against the admin `system.xml` structure, and this flag has
 * no admin field by design. Verified on Magento 2.4.6:
 * `The "..." path doesn't exist. Verify and try again.` The old docblock
 * and `etc/config.xml` comment both advertised a `config:set` recipe
 * that could not have worked. A `core_config_data` row inserted by hand
 * is the other route that ScopeConfig honours.
 *
 * The flag used to live at `payment/two_payment/hide_when_overlay_installed`
 * (TWO-25191 moved it). That namespace is merchant-owned — every other
 * key under it is a real merchant setting with an admin field — whereas
 * this is a deploy/rollout switch, so it belongs next to the other
 * `two_brand_synthesis` rollout knobs.
 *
 * Neither path carries an `etc/config.xml` default: the default lives in
 * DEFAULT_HIDE below, so an absent (null) value is distinguishable from
 * an explicit `0`, which is what makes the legacy-path fallback in
 * `shouldHide()` work at all. Read order is new path, then legacy path,
 * then DEFAULT_HIDE — so an install that set the old path before the move
 * keeps its choice. The LEGACY_HIDE_FLAG_PATH read can be deleted once
 * the brand-synthesis rollout completes and any remaining
 * `core_config_data` / env.php entries on the old path are migrated.
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
    private const HIDE_FLAG_PATH = 'two_brand_synthesis/hide_payment_section/enabled';
    private const LEGACY_HIDE_FLAG_PATH = 'payment/two_payment/hide_when_overlay_installed';
    private const DEFAULT_HIDE = true;
    private const TARGET_SECTIONS = ['two_general', 'two_payment', 'two_search', 'two_version'];

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

        foreach ([self::HIDE_FLAG_PATH, self::LEGACY_HIDE_FLAG_PATH] as $path) {
            $value = $this->scopeConfig->getValue($path);
            // Only an ABSENT value falls through to the next path. `0` is a
            // deliberate merchant opt-out and must win over the legacy read
            // and over DEFAULT_HIDE alike.
            if ($value !== null && $value !== '') {
                return (bool)$value;
            }
        }

        return self::DEFAULT_HIDE;
    }
}
