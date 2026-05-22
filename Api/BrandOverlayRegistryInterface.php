<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Two\Gateway\Api;

/**
 * Registry of brand-overlay packages that have declared themselves
 * present in this Magento install.
 *
 * Overlay packages (e.g. ABN_Gateway) register their payment method
 * code with the registry via DI, telling Two_Gateway "an alternative
 * brand-bound payment method is installed alongside me". Two_Gateway
 * uses this to drive UX decisions like hiding the parent-brand
 * `two_payment` admin config section so merchants don't see two
 * configurable payment groups by default.
 *
 * Behaviour:
 *   - Empty registry (no overlays declared) = standalone Two_Gateway
 *     install. Admin shows `two_payment` as normal.
 *   - Non-empty registry = at least one overlay installed. Admin
 *     hides `two_payment` by default; merchant opts in to re-show
 *     via `payment/two_payment/hide_when_overlay_installed = 0`.
 */
interface BrandOverlayRegistryInterface
{
    /**
     * @return bool True iff at least one overlay package is registered.
     */
    public function isOverlayInstalled(): bool;

    /**
     * @return array<string, string> Map of overlay key => method code.
     */
    public function getOverlays(): array;
}
