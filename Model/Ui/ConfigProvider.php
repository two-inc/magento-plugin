<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Model\Ui;

use Magento\Checkout\Model\ConfigProviderInterface;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\View\Asset\Repository as AssetRepository;
use Magento\Store\Model\StoreManagerInterface;
use Two\Gateway\Api\BrandRegistryInterface;
use Two\Gateway\Api\Config\RepositoryInterface as ConfigRepository;
use Two\Gateway\Service\UrlCookie;
use Two\Gateway\Service\Api\Adapter;
use Two\Gateway\Service\Api\SupportedCompanyTypes;
use Two\Gateway\Model\Two;

/**
 * Ui Config Provider.
 *
 * Populates `window.checkoutConfig.payment[<code>]` with the runtime
 * config the gateway_method renderer needs. The `$code` constructor
 * argument decides which subtree of `payment` gets populated, so
 * brand-overlay packages can declare a
 * virtualType of this class with `code='acme_payment'` and a
 * brand-bound BrandRegistryInterface to expose their own subtree
 * without re-implementing the body of getConfig().
 *
 * The Two-branded binding defaults to ConfigRepository::CODE
 * ('two_payment') so existing installs keep their current behaviour
 * without an etc/di.xml change.
 */
class ConfigProvider implements ConfigProviderInterface
{
    /**
     * Placeholder the renderer substitutes the buyer's company name into.
     *
     * The company name is only ever known client-side (the renderer's
     * `companyName` observable, populated by company search or manual
     * entry), so the %2 argument cannot be resolved here. Passing this
     * sentinel rather than leaving %2 dangling keeps both placeholders in
     * the msgid, so translators see the full sentence shape and the
     * translated string round-trips through Magento's Phrase renderer
     * unchanged.
     */
    public const COMPANY_NAME_TOKEN = '{{companyName}}';

    /**
     * Same sentinel mechanism as COMPANY_NAME_TOKEN, for the organisation
     * number (TWO-25326 §7.3: the tile's ONLY company display is now this
     * sentence, so the number has to be substitutable into it same as the
     * name).
     */
    public const COMPANY_NUMBER_TOKEN = '{{companyNumber}}';

    /** @var string */
    private $code;

    /**
     * @var ConfigRepository
     */
    private $configRepository;

    /** @var BrandRegistryInterface */
    private $brandRegistry;

    /**
     * @var Two
     */
    private $two;

    /**
     * @var Adapter
     */
    private $adapter;

    /**
     * @var AssetRepository
     */
    private $assetRepository;

    /**
     * @var CheckoutSession
     */
    private $checkoutSession;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var SupportedCompanyTypes
     */
    private $supportedCompanyTypes;

    /**
     * @param string $code Payment-method code (overlay-specific). Defaults
     *                     to the Two-branded value for backward
     *                     compatibility with installs that don't override.
     */
    public function __construct(
        ConfigRepository $configRepository,
        BrandRegistryInterface $brandRegistry,
        Adapter $adapter,
        Two $two,
        AssetRepository $assetRepository,
        CheckoutSession $checkoutSession,
        StoreManagerInterface $storeManager,
        SupportedCompanyTypes $supportedCompanyTypes,
        ?string $code = null
    ) {
        $this->configRepository = $configRepository;
        $this->brandRegistry = $brandRegistry;
        $this->adapter = $adapter;
        $this->two = $two;
        $this->assetRepository = $assetRepository;
        $this->checkoutSession = $checkoutSession;
        $this->storeManager = $storeManager;
        $this->supportedCompanyTypes = $supportedCompanyTypes;
        $this->code = $code ?? $brandRegistry->getCode();
    }

    /**
     * Registry answer for the quote's current billing country, keyed by
     * lowercased ISO code — the renderer's warm-start memo entry. Empty
     * when the quote has no billing country yet; fail-soft (the service
     * resolves registry errors to an empty type list, which the renderer
     * treats as business-only checkout).
     *
     * @return array<string,string[]>
     */
    private function getSupportedCompanyTypesSeed(): array
    {
        $country = (string)$this->checkoutSession->getQuote()->getBillingAddress()->getCountryId();
        if ($country === '') {
            return [];
        }
        return [strtolower($country) => $this->supportedCompanyTypes->getForCountry($country)];
    }

    /**
     * Retrieve assoc array of checkout configuration
     *
     * @return array
     */
    public function getConfig(): array
    {
        $merchant = null;
        if ($this->configRepository->getApiKey()) {
            $merchant = $this->adapter->execute('/v1/merchant/verify_api_key', [], 'GET');
        }
        $orderIntentConfig = [
            'extensionPlatformName' => $this->configRepository->getExtensionPlatformName(),
            'extensionDBVersion' => $this->configRepository->getExtensionDBVersion(),
            'weightUnit' => $this->configRepository->getWeightUnit(),
            'merchant' => $merchant,
        ];

        $tryAgainLater = __('Please try again later.');
        $soleTraderaccountCouldNotBeVerified = __('Your sole trader account could not be verified.');
        $paymentTerms = __("payment terms");
        $brandParams = $this->buildBrandQueryString();
        $paymentTermsLink = $this->configRepository->getCheckoutPageUrl() . '/terms' . $brandParams;
        $minimumOrder = $this->two->getMinimumOrderVisibility($this->checkoutSession->getQuote());

        return [
            'payment' => [
                $this->code => [
                    'checkoutApiUrl' => $this->configRepository->getCheckoutApiUrl(),
                    'checkoutPageUrl' => $this->configRepository->getCheckoutPageUrl(),
                    'brand' => $this->configRepository->getBrand(),
                    'brandVersion' => $this->configRepository->getBrandVersion(),
                    'redirectUrlCookieCode' => UrlCookie::COOKIE_NAME,
                    'isOrderIntentEnabled' => $this->configRepository->isOrderIntentEnabled(),
                    'isInvoiceEmailsEnabled' => $this->configRepository->isInvoiceEmailsEnabled(),
                    'orderIntentConfig' => $orderIntentConfig,
                    'isCompanySearchEnabled' => $this->configRepository->isCompanySearchEnabled(),
                    'isAddressSearchEnabled' => $this->configRepository->isAddressSearchEnabled(),
                    'companySearchLimit' => 50,
                    // Warm-start seed for the renderer's per-country
                    // supported-company-types memo: the quote's current
                    // billing country resolved server-side (the merchant
                    // API key never reaches the browser). Other countries
                    // are fetched live via GET /V1/two/supported-company-types
                    // as the buyer edits the billing address.
                    'supportedCompanyTypes' => $this->getSupportedCompanyTypesSeed(),
                    'isDepartmentFieldEnabled' => $this->configRepository->isDepartmentEnabled(),
                    'isProjectFieldEnabled' => $this->configRepository->isProjectEnabled(),
                    'isOrderNoteFieldEnabled' => $this->configRepository->isOrderNoteEnabled(),
                    'isPONumberFieldEnabled' => $this->configRepository->isPONumberEnabled(),
                    'availableBuyerTerms' => $this->configRepository->getAllBuyerTerms(),
                    'defaultPaymentTerm' => $this->configRepository->getDefaultPaymentTerm(),
                    'selectedPaymentTerm' => (int)$this->checkoutSession->getTwoSelectedTerm()
                        ?: $this->configRepository->getDefaultPaymentTerm(),
                    'currencySymbol' => $this->getCurrencySymbol(),
                    // Server-resolved minimum-order constraints in the display
                    // currency, for the renderer's client-side visibility gate
                    // (hide below min; on Amasty, where isAvailable offers the
                    // method unconditionally, this also drives showing above it).
                    // minimumOrderUnresolved is true when an active minimum could
                    // not be projected into the display currency (missing FX
                    // rate) → the renderer hides, matching the server gate's
                    // fail-closed stance rather than failing open.
                    'minimumOrder' => $minimumOrder['minimums'],
                    'minimumOrderUnresolved' => $minimumOrder['unresolved'],
                    'subtitleHtml' => $this->getSubtitleHtml(),
                    'surchargeDescription' => $this->configRepository->getSurchargeLineDescription(),
                    'isPaymentTermsEnabled' => true,
                    // null ⇒ the brand suppressed the notice; the renderer
                    // emits no element at all. Replaces the former
                    // `orderIntentApprovedMessage`, which the renderer fed to
                    // the KO `messages` region — a surface checkout clears on
                    // every update, so the notice was effectively invisible.
                    'orderIntentApprovedNotice' => $this->getOrderIntentApprovedNotice(),
                    'orderIntentDeclinedNotice' => $this->getOrderIntentDeclinedNotice(),
                    // The former `orderIntentDeclinedMessage` toast (a plain
                    // "declined" string, fed to the renderer's message
                    // region) is removed — the 2026-08-03 ruling replaced it
                    // with the persistent `orderIntentDeclinedNotice` above.
                    // Found dead in adversarial review, 2026-08-04: a comment
                    // here once claimed it was kept for the generic HTTP/
                    // technical-failure path, but
                    // processOrderIntentErrorResponse() has only ever used
                    // `generalErrorMessage` for that — this key was assigned
                    // once on the renderer and never read.
                    'generalErrorMessage' => __(
                        'Something went wrong with your request to %1. %2',
                        $this->brandRegistry->getProductName(),
                        $tryAgainLater
                    ),
                    // TWO-25326 §6a: the Two method stays selectable with a
                    // manual (name-only, no organisation number) capture —
                    // it is blocked at submit instead, matching the WC/PS/
                    // Hyvä pattern rather than Magento's previous silent
                    // no-op (no block, no message, no order).
                    'companyRequiredMessage' => __(
                        'Please select your company before paying with %1.',
                        $this->brandRegistry->getProductName()
                    ),
                    'invalidEmailListMessage' => __('Please ensure that your invoice email address list only contains valid email addresses separated by commas.'),
                    'paymentTermsMessage' => __(
                        'I accept the %1 and authorize %2 to process my data automatically.',
                        sprintf('<a href="%s" target="_blank">%s</a>', $paymentTermsLink, $paymentTerms),
                        $this->brandRegistry->getProviderFullName()
                    ),
                    'termsNotAcceptedMessage' => __('You must accept %1 to place order.', $paymentTerms),
                    'soleTraderErrorMessage' => __(
                        'Something went wrong with your request to %1. %2',
                        $this->brandRegistry->getProductName(),
                        $soleTraderaccountCouldNotBeVerified
                    ),
                ],
            ],
        ];
    }

    /**
     * Resolve the buyer-facing "order intent approved" notice for the
     * storefront renderer.
     *
     * Returns null when the active brand suppressed the notice — the
     * renderer then emits no DOM element at all, rather than an empty
     * wrapper. Otherwise returns both resolved copy variants plus the
     * token the renderer substitutes the company name into:
     *
     *   withCompany    — company name known (the normal case; an order
     *                    intent is only placed once the buyer's company
     *                    is resolved)
     *   withoutCompany — defensive fallback
     *
     * Suppression is driven by the brand's
     * <intent_approved_notice_enabled> switch. The copy override
     * <intent_approved_notice> is wording only: non-empty replaces the
     * company-known variant, absent/empty leaves the platform default.
     * See BrandRegistryInterface for both contracts.
     *
     * TWO-25326 2026-08-03 ruling, §7.3: this is now the ONLY place the
     * captured company name/number are displayed in the payment tile — the
     * standalone `.two-company-label` text (§7, pre-ruling) is removed, not
     * supplemented. Default wording is the literal ticket copy, with the
     * company number substituted the same way the company name always was.
     *
     * @return array{withCompany:string,withoutCompany:string,companyNameToken:string,companyNumberToken:string}|null
     */
    private function getOrderIntentApprovedNotice(): ?array
    {
        if (!$this->brandRegistry->isIntentApprovedNoticeEnabled()) {
            return null;
        }

        $override = $this->brandRegistry->getIntentApprovedNotice();

        $productName = $this->brandRegistry->getProductName();

        // The default is spelled as a literal __() argument, not routed
        // through a variable, so `i18n:collect-phrases` and the overlay
        // repos' i18n audit can both still see it. The override branch
        // takes a variable by necessity — a brand's own copy is its own
        // module's msgid and lives in that module's i18n CSV. %3 (company
        // number) is a new argument as of the 2026-08-03 ruling; an
        // existing override string that only references %1/%2 keeps
        // working unchanged, and one that wants the number can add %3.
        $withCompany = $override === null
            ? __(
                'This order by %2 (%3) is likely to be accepted by %1',
                $productName,
                self::COMPANY_NAME_TOKEN,
                self::COMPANY_NUMBER_TOKEN
            )
            : __($override, $productName, self::COMPANY_NAME_TOKEN, self::COMPANY_NUMBER_TOKEN);

        return [
            'withCompany' => (string)$withCompany,
            'withoutCompany' => (string)__(
                'This order is likely to be accepted by %1',
                $productName
            ),
            'companyNameToken' => self::COMPANY_NAME_TOKEN,
            'companyNumberToken' => self::COMPANY_NUMBER_TOKEN,
        ];
    }

    /**
     * Resolve the buyer-facing "order intent NOT approved" notice — the
     * §7.3 counterpart to getOrderIntentApprovedNotice() above, added by the
     * 2026-08-03 ruling. Same shape, same suppression switch (a brand that
     * turns the notice off gets neither variant — TWO-25326 §7.2 treats
     * "the intent message" as one on/off unit, approved or declined), and a
     * SEPARATE copy override so a brand with its own approved wording is not
     * forced to also take the vanilla declined wording (§7.4).
     *
     * This is the "not approved" business outcome only (a clean response
     * with `approved: false`) — a technical/HTTP failure is a different
     * surface, `generalErrorMessage`, handled by
     * processOrderIntentErrorResponse() in gateway_method.js.
     *
     * @return array{withCompany:string,withoutCompany:string,companyNameToken:string,companyNumberToken:string}|null
     */
    private function getOrderIntentDeclinedNotice(): ?array
    {
        if (!$this->brandRegistry->isIntentApprovedNoticeEnabled()) {
            return null;
        }

        $override = $this->brandRegistry->getIntentDeclinedNotice();

        $productName = $this->brandRegistry->getProductName();

        $withCompany = $override === null
            ? __(
                '%1 is not available for this order by %2 (%3)',
                $productName,
                self::COMPANY_NAME_TOKEN,
                self::COMPANY_NUMBER_TOKEN
            )
            : __($override, $productName, self::COMPANY_NAME_TOKEN, self::COMPANY_NUMBER_TOKEN);

        return [
            'withCompany' => (string)$withCompany,
            'withoutCompany' => (string)__(
                '%1 is not available for this order',
                $productName
            ),
            'companyNameToken' => self::COMPANY_NAME_TOKEN,
            'companyNumberToken' => self::COMPANY_NUMBER_TOKEN,
        ];
    }

    /**
     * Get the currency symbol for the current store's display currency.
     */
    private function getCurrencySymbol(): string
    {
        try {
            $store = $this->storeManager->getStore();
            return $store->getCurrentCurrency()->getCurrencySymbol() ?: $store->getCurrentCurrencyCode();
        } catch (\Exception $e) {
            return '';
        }
    }

    /**
     * Resolve the brand's checkout subtitle for the storefront renderer.
     *
     * The string is brand data (BrandRegistryInterface::getCheckoutSubtitle,
     * sourced from brand.xml). The vanilla Two brand returns '' → no
     * subtitle. We only pass a non-empty key to the translator, so an
     * unmapped locale falls back to the (brand-owned) source key rather
     * than ever leaking a vanilla key. May contain HTML (e.g. a link);
     * the KO template binds it via `html:`.
     */
    private function getSubtitleHtml(): string
    {
        $key = $this->brandRegistry->getCheckoutSubtitle();
        return $key === '' ? '' : (string)__($key);
    }

    /**
     * Build query string with brand parameters.
     *
     * @return string e.g. "?brand=<tag>&brandVersion=qa" or ""
     *                where <tag> comes from BrandRegistryInterface::getBrandTag().
     */
    private function buildBrandQueryString(): string
    {
        $params = [];
        $brand = $this->configRepository->getBrand();
        if ($brand !== '') {
            $params['brand'] = $brand;
        }
        $brandVersion = $this->configRepository->getBrandVersion();
        if ($brandVersion !== '') {
            $params['brandVersion'] = $brandVersion;
        }
        return $params ? '?' . http_build_query($params) : '';
    }
}
