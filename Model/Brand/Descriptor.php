<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Model\Brand;

/**
 * Install-time-immutable brand identity. Materialised from
 * one <brand> element in a module's etc/brand.xml by Loader.
 *
 * All mutable settings (api_key, mode, payment_terms selections,
 * surcharge_grid …) are read live from CCD per request via
 * ScopeConfigInterface keyed on payment/<code>/<setting>. The
 * Descriptor never caches those.
 */
final class Descriptor
{
    /**
     * @param string $code Magento payment-method code (e.g. "two_payment").
     * @param string $sectionPrefix Short identifier used as the prefix
     *        for synthesised admin Configuration section IDs and the
     *        synthesised admin tab id. Empty string ⇒ derive from `code`
     *        by stripping a trailing `_payment` suffix.
     * @param int $tabSortOrder Admin Configuration tab sortOrder.
     * @param string $provider Short brand name shown in admin headers.
     * @param string $providerFullName Legal entity name.
     * @param string $productName Customer-facing product label.
     * @param string $tabLabel Admin Configuration tab label.
     * @param string $tabCssClass CSS class on the admin tab `<li>`.
     * @param string $checkoutUrlTemplate sprintf template, %s = env tag.
     * @param string $brandTag Disambiguator for shared-host checkouts; '' = none.
     * @param string $signUpUrl Merchant sign-up link shown in admin header.
     * @param string $documentationUrl Plugin docs URL shown in admin header.
     * @param string $apiBaseUrl Outbound API base URL.
     * @param int[] $availablePaymentTerms Buyer-selectable terms in days.
     * @param array{amount:float,currency:string}|null $surchargeFixedMax
     * @param string[] $cspOrigins Additional CSP fetch-policy origins.
     * @param string $adminResource ACL resource for the brand's admin form.
     * @param array<array{label:string,module:string}> $moduleLabelChain Version-panel rows.
     * @param string[] $allowedCurrencies ISO codes; empty = unrestricted.
     * @param string[] $allowedCountries ISO codes; empty = unrestricted.
     * @param array<string,string> $extraHttpHeaders name=>value, decoration on outbound requests.
     * @param string[] $suppressedFields `section_suffix/group/field` paths to hide in the synthesised admin form.
     * @param bool $inlineTermFees Whether to render the per-term merchant fee beside each Payment Terms checkbox in admin.
     * @param array{amount:float,currency:string}|null $minimumOrder Minimum order value for the method to be offered; null = no minimum.
     */
    public function __construct(
        private readonly string $code,
        private readonly string $sectionPrefix,
        private readonly int $tabSortOrder,
        private readonly string $provider,
        private readonly string $providerFullName,
        private readonly string $productName,
        private readonly string $tabLabel,
        private readonly string $tabCssClass,
        private readonly string $checkoutUrlTemplate,
        private readonly string $brandTag,
        private readonly string $signUpUrl,
        private readonly string $documentationUrl,
        private readonly string $apiBaseUrl,
        private readonly array $availablePaymentTerms,
        private readonly ?array $surchargeFixedMax,
        private readonly array $cspOrigins,
        private readonly string $adminResource,
        private readonly array $moduleLabelChain,
        private readonly array $allowedCurrencies,
        private readonly array $allowedCountries,
        private readonly array $extraHttpHeaders,
        private readonly array $suppressedFields = [],
        private readonly bool $inlineTermFees = true,
        private readonly string $checkoutSubtitle = '',
        private readonly ?array $minimumOrder = null
    ) {
    }

    /**
     * Whether the admin Payment Terms checkbox list should render the
     * per-term merchant fee inline beside each checkbox. Default true.
     * Brand overlays set `<inline_term_fees>false</inline_term_fees>` in
     * brand.xml when their pricing contract makes the per-term cost
     * unhelpful to surface in admin.
     */
    public function getInlineTermFees(): bool
    {
        return $this->inlineTermFees;
    }

    /**
     * `section_suffix/group/field` paths to hide in the synthesised
     * admin Configuration form. Consumed by SynthesiseBrandAdminForm.
     *
     * @return string[]
     */
    public function getSuppressedFields(): array
    {
        return $this->suppressedFields;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    /**
     * Short identifier used as the prefix for synthesised admin
     * Configuration section IDs (e.g. `two_general`, `two_payment`,
     * `two_search`, `two_version`) and the admin tab id
     * (`{prefix}_gateway`). Falls back to `code` minus a trailing
     * `_payment` suffix when not explicitly declared.
     */
    public function getSectionPrefix(): string
    {
        if ($this->sectionPrefix !== '') {
            return $this->sectionPrefix;
        }
        // Derive: `two_payment` → `two`, `abn_payment` → `abn`.
        // Strictly strip a TRAILING `_payment` suffix only; using
        // strstr() would incorrectly shorten a hypothetical
        // `foo_payment_method` to `foo`.
        if (substr($this->code, -8) === '_payment') {
            return substr($this->code, 0, -8);
        }
        return $this->code;
    }

    public function getTabSortOrder(): int
    {
        return $this->tabSortOrder;
    }

    public function getProvider(): string
    {
        return $this->provider;
    }

    public function getProviderFullName(): string
    {
        return $this->providerFullName !== '' ? $this->providerFullName : $this->provider;
    }

    public function getProductName(): string
    {
        return $this->productName;
    }

    public function getTabLabel(): string
    {
        return $this->tabLabel;
    }

    public function getTabCssClass(): string
    {
        return $this->tabCssClass;
    }

    public function getCheckoutUrlTemplate(): string
    {
        return $this->checkoutUrlTemplate;
    }

    public function getBrandTag(): string
    {
        return $this->brandTag;
    }

    /**
     * i18n source key for the checkout payment-method subtitle, or '' when
     * the brand defines none. The vanilla Two brand returns ''; brand
     * overlays set <checkout_subtitle> in brand.xml. Empty means the
     * renderers emit no subtitle text — the key is never passed to the
     * translator, so no untranslated key can leak into the storefront.
     */
    public function getCheckoutSubtitle(): string
    {
        return $this->checkoutSubtitle;
    }

    public function getSignUpUrl(): string
    {
        return $this->signUpUrl;
    }

    public function getDocumentationUrl(): string
    {
        return $this->documentationUrl;
    }

    public function getApiBaseUrl(): string
    {
        return $this->apiBaseUrl;
    }

    /** @return int[] */
    public function getAvailablePaymentTerms(): array
    {
        return $this->availablePaymentTerms;
    }

    /** @return array{amount:float,currency:string}|null */
    public function getSurchargeFixedMax(): ?array
    {
        return $this->surchargeFixedMax;
    }

    /** @return array{amount:float,currency:string}|null */
    public function getMinimumOrder(): ?array
    {
        return $this->minimumOrder;
    }

    /** @return string[] */
    public function getCspOrigins(): array
    {
        return $this->cspOrigins;
    }

    public function getAdminResource(): string
    {
        return $this->adminResource;
    }

    /** @return array<array{label:string,module:string}> */
    public function getModuleLabelChain(): array
    {
        return $this->moduleLabelChain;
    }

    /** @return string[] */
    public function getAllowedCurrencies(): array
    {
        return $this->allowedCurrencies;
    }

    /** @return string[] */
    public function getAllowedCountries(): array
    {
        return $this->allowedCountries;
    }

    /** @return array<string,string> */
    public function getExtraHttpHeaders(): array
    {
        return $this->extraHttpHeaders;
    }
}
