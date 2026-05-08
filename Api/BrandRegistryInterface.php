<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Api;

/**
 * Per-brand identity values that vary between distributable packages.
 *
 * One source tree produces two Composer packages: two-inc/magento2
 * (Two_Gateway) and two-inc/magento-abn-plugin (ABN_Gateway). Each
 * package's etc/di.xml binds this interface to the appropriate
 * implementation in Two\Gateway\Brand\.
 *
 * Callers must depend on this interface, not on the concrete impls,
 * and not on the legacy brand constants that previously lived on
 * Two\Gateway\Api\Config\RepositoryInterface.
 */
interface BrandRegistryInterface
{
    /**
     * Short brand name (e.g. "Two", "ABN AMRO"). Used in admin
     * surfaces and a handful of merchant-facing strings.
     */
    public function getProvider(): string;

    /**
     * Legal entity name. Used in T&Cs and similar formal contexts.
     */
    public function getProviderFullName(): string;

    /**
     * Customer-facing product label (e.g. "Two", "ABN AMRO Zakelijk
     * op Rekening"). Preferred over getProvider() for buyer-visible
     * strings.
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
     * Short brand tag used to decorate non-production checkout URLs
     * (e.g. `?brand=achterafbetalen`). Empty string ('') means do not
     * decorate — the URL host already conveys the brand. The default
     * production behaviour for both brands is to return ''; non-prod
     * implementations may return a brand tag so that shared sandbox/
     * staging hosts can route correctly.
     */
    public function getBrandTag(): string;
}
