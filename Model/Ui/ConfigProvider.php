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
use Two\Gateway\Api\Log\RepositoryInterface as LogRepository;
use Two\Gateway\Service\UrlCookie;
use Two\Gateway\Service\Api\SupportedCompanyTypes;
use Two\Gateway\Service\Merchant\ApiKeyStatus;
use Two\Gateway\Service\Merchant\SettingsProvider;
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
     * number (TWO-25326: the tile's ONLY company display is now this
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
     * @var ApiKeyStatus
     */
    private $apiKeyStatus;

    /**
     * @var SettingsProvider
     */
    private $settingsProvider;

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
     * @var CheckoutTileCopy
     */
    private $checkoutTileCopy;

    /**
     * @var LogRepository
     */
    private $logRepository;

    /** @var bool */
    private $withholdLogged = false;

    /**
     * @param string $code Payment-method code (overlay-specific). Defaults
     *                     to the Two-branded value for backward
     *                     compatibility with installs that don't override.
     */
    public function __construct(
        ConfigRepository $configRepository,
        BrandRegistryInterface $brandRegistry,
        ApiKeyStatus $apiKeyStatus,
        SettingsProvider $settingsProvider,
        Two $two,
        AssetRepository $assetRepository,
        CheckoutSession $checkoutSession,
        StoreManagerInterface $storeManager,
        SupportedCompanyTypes $supportedCompanyTypes,
        CheckoutTileCopy $checkoutTileCopy,
        LogRepository $logRepository,
        ?string $code = null
    ) {
        $this->configRepository = $configRepository;
        $this->brandRegistry = $brandRegistry;
        $this->apiKeyStatus = $apiKeyStatus;
        $this->settingsProvider = $settingsProvider;
        $this->two = $two;
        $this->assetRepository = $assetRepository;
        $this->checkoutSession = $checkoutSession;
        $this->storeManager = $storeManager;
        $this->supportedCompanyTypes = $supportedCompanyTypes;
        $this->checkoutTileCopy = $checkoutTileCopy;
        $this->logRepository = $logRepository;
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
        $quote = $this->checkoutSession->getQuote();
        $country = (string)$quote->getBillingAddress()->getCountryId();
        if ($country === '') {
            return [];
        }
        return [
            strtolower($country) => $this->supportedCompanyTypes->getForCountry(
                $country,
                (int)$quote->getStoreId() ?: null
            ),
        ];
    }

    /**
     * Retrieve assoc array of checkout configuration
     *
     * @return array
     */
    public function getConfig(): array
    {
        // Emitting nothing here withholds company search AND the tile's
        // renderer: `js/model/brand-config.js::getActiveTwoBrandCode()` finds
        // the active brand by scanning `window.checkoutConfig.payment` for a
        // subtree carrying a truthy `redirectUrlCookieCode`, and both mount
        // only when that resolves. So it must ask exactly the question
        // Two::isAvailable() asks — a rejected key, or no key (ABN-533) — or
        // an outage leaves the method offered with no config to render it.
        //
        // No store id is passed, matching every other configRepository read
        // in this method: ConfigRepository resolves a null store id through
        // ScopeInterface::SCOPE_STORE, i.e. the current store. On a checkout
        // render that is the quote's store, which is the id
        // Two::isAvailable() resolves from the quote and passes explicitly —
        // so both surfaces judge the same store's key and agree.
        if ($this->apiKeyStatus->isDefinitiveFailure()) {
            // Once per request: getConfig() is evaluated on every cart,
            // checkout and payment-information render.
            if (!$this->withholdLogged) {
                $this->withholdLogged = true;
                $apiKeyStatus = $this->apiKeyStatus->getStatus();
                $this->logRepository->addDebugLog(
                    sprintf(
                        '%s checkout config withheld (tile and company search): API key verdict "%s"',
                        $this->code,
                        $apiKeyStatus['status']
                    ),
                    ['status' => $apiKeyStatus['status'], 'http_status' => $apiKeyStatus['code']]
                );
            }
            return [];
        }
        // Identity only, and one shape whichever source supplies it: the
        // verdict's `merchant` is the whole verify_api_key body, and the
        // record's commercial fields have no business in the page. The verdict
        // carries a merchant only on a success, so a fall-through reads the
        // never-expiring record instead.
        $merchant = $this->settingsProvider->identityFrom($this->apiKeyStatus->getStatus()['merchant'] ?? null)
            ?? $this->settingsProvider->getMerchantIdentity();
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
        $defaultPaymentTerm = $this->configRepository->getDefaultPaymentTerm() ?? 0;

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
                    'customHeaders' => $this->configRepository->getBrowserCustomHeaders(),
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
                    // 0, not a day count, when no term is offered (ABN-544).
                    'defaultPaymentTerm' => $defaultPaymentTerm,
                    'selectedPaymentTerm' => (int)$this->checkoutSession->getTwoSelectedTerm()
                        ?: $defaultPaymentTerm,
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
                    'subtitleHtml' => $this->checkoutTileCopy->getSubtitleHtml(),
                    'showAboutLink' => $this->checkoutTileCopy->isAboutLinkVisible(),
                    'aboutLinkUrl' => $this->checkoutTileCopy->getAboutLinkUrl(),
                    'aboutLinkText' => $this->checkoutTileCopy->getAboutLinkText(),
                    'displayTooltips' => $this->configRepository->isDisplayTooltipsEnabled(),
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
                    // region) is removed — TWO-25326 replaced it with the
                    // persistent `orderIntentDeclinedNotice` above.
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
                    // TWO-25326: the Two method stays selectable with a
                    // manual (name-only, no organisation number) capture —
                    // it is blocked at submit instead, matching the WC/PS/
                    // Hyvä pattern rather than Magento's previous silent
                    // no-op (no block, no message, no order).
                    'companyRequiredMessage' => __(
                        'Please select your company before paying with %1.',
                        $this->brandRegistry->getProductName()
                    ),
                    'invalidEmailListMessage' => __('Please ensure that your invoice email address list only contains valid email addresses separated by commas.'),
                    // TWO-25503: shown when the term selected at render time is
                    // no longer among the terms the server offers at submit —
                    // see the renderer's isSelectedTermStillAvailable().
                    'termUnavailableMessage' => __(
                        'The payment terms you selected are no longer available. Please select your payment terms again.'
                    ),
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
     * <intent_approved_notice> is wording only: non-blank replaces the
     * company-known variant, absent/blank leaves the platform default.
     * See BrandRegistryInterface for both contracts.
     *
     * TWO-25326: this is the ONLY place the captured company NAME is
     * displayed in the payment tile — the standalone `.two-company-label`
     * text is removed, not supplemented. Default wording is the literal
     * ticket copy, with the company number substituted the same way the
     * company name always was. The company NUMBER also renders separately,
     * notice-independent, via the tile's `.two-company-id-text` label —
     * see gateway_method.html.
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
        // number) is a TWO-25326 addition; an existing override string
        // that only references %1/%2 keeps working unchanged, and one
        // that wants the number can add %3.
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
     * counterpart to getOrderIntentApprovedNotice() above, added by the
     * same TWO-25326 work. Same shape, and its own switch and copy override —
     * <intent_declined_notice_enabled> / <intent_declined_notice> — so a
     * brand suppresses or rewords the two outcomes separately once it
     * declares the declined switch or ships non-blank declined copy
     * (TWO-25326).
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
        if (!$this->brandRegistry->isIntentDeclinedNoticeEnabled()) {
            return null;
        }

        $override = $this->brandRegistry->getIntentDeclinedNotice();

        $productName = $this->brandRegistry->getProductName();

        // Literal default for the same i18n-collection reason as the
        // approved notice above.
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
