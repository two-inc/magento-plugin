<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Api;

/**
 * Per-brand identity values that vary between distributable packages
 * of the Two payment gateway. The default binding lives in this
 * package; downstream brand-overlay packages may rebind this
 * interface to their own implementation via DI preference.
 *
 * Callers must depend on this interface, not on the concrete impls.
 */
interface BrandRegistryInterface
{
    /**
     * Short brand name (e.g. "Two"). Used in admin surfaces and a
     * handful of merchant-facing strings.
     */
    public function getProvider(): string;

    /**
     * Legal entity name. Used in T&Cs and similar formal contexts.
     */
    public function getProviderFullName(): string;

    /**
     * Customer-facing product label (e.g. "Two"). Preferred over
     * getProvider() for buyer-visible strings.
     */
    public function getProductName(): string;

    /**
     * sprintf template for the brand's checkout-page host. Receives
     * the env tag (e.g. "sandbox", "staging") as %s and yields the
     * full host the buyer is redirected to for invoice signing.
     */
    public function getCheckoutUrlTemplate(): string;

    /**
     * Buyer-selectable payment terms (in days) supported by this
     * brand's commercial agreement.
     *
     * @return int[]
     */
    public function getAvailablePaymentTerms(): array;

    /**
     * Maximum allowed value of a fixed-amount surcharge configured
     * by the merchant, expressed in a specific currency. Returning
     * null means there is no upper bound — any positive value is
     * acceptable. Calling code must interpret null as "no max" and
     * skip the upper-bound check.
     *
     * @return array{amount: float, currency: string}|null
     */
    public function getSurchargeFixedMax(): ?array;

    /**
     * Minimum order value required for this brand's payment method to
     * be offered at checkout: amount + currency + basis ('net' = basket
     * excluding tax, 'gross' = including). Basis is always explicit in
     * brand.xml — funding-partner rules and platform country defaults
     * may differ, so the brand declares its requirement unambiguously.
     * Returning null means there is no minimum. Baskets in other
     * currencies are converted to this currency via the store's
     * exchange rates before comparing.
     *
     * @return array{amount: float, currency: string, basis: string}|null
     */
    public function getMinimumOrder(): ?array;

    /**
     * Short brand tag used to decorate non-production checkout URLs
     * (e.g. `?brand=<tag>`). Empty string ('') means do not decorate
     * — the URL host already conveys the brand. Implementations may
     * return a brand tag so that shared sandbox/staging hosts can
     * route correctly.
     */
    public function getBrandTag(): string;

    /**
     * i18n source key for the checkout payment-method subtitle, or '' when
     * the brand defines none. The vanilla Two brand returns ''; brand
     * overlays supply one via <checkout_subtitle> in brand.xml. Renderers
     * must treat '' as "no subtitle" and never pass it to the translator,
     * so an unmapped key can never leak into the storefront.
     */
    public function getCheckoutSubtitle(): string;

    /**
     * Merchant sign-up URL shown on the admin config header block.
     */
    public function getSignUpUrl(): string;

    /**
     * Plugin documentation URL shown on the admin config header block.
     */
    public function getDocumentationUrl(): string;

    /**
     * Magento payment-method code for the active brand (e.g.
     * "two_payment", "abn_payment"). Used to build brand-aware
     * `payment/<code>/*` CCD paths from a single shared codebase
     * — callers do not hold this value in their own constructor
     * args.
     */
    public function getCode(): string;

    /**
     * Whether the admin Payment Terms checkbox list should render the
     * per-term merchant fee inline beside each checkbox (e.g.
     * "30 days (1.50% + 0.50)"). Default true. Brand overlays return
     * false to hide the inline fee preview when their pricing contract
     * makes the per-term cost unhelpful to surface in admin.
     */
    public function getInlineTermFees(): bool;

    /**
     * Ordered label => module-name map for the admin Version panel
     * (Stores → Configuration → [Brand] → Version). Brand
     * overlays append their theme modules to the parent runtime
     * rows; the Version block renders one row per ComponentRegistrar-
     * resolvable module and silently skips unregistered entries.
     *
     * @return array<string,string>
     */
    public function getModuleLabelChain(): array;
}
