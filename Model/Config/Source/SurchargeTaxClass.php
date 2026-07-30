<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Model\Config\Source;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Data\OptionSourceInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Tax\Model\TaxClass\Source\Product as ProductTaxClassSource;
use Two\Gateway\Api\Config\RepositoryInterface as ConfigRepository;

/**
 * Product Tax Class options for the surcharge tax treatment selector.
 *
 * The selector is NEVER auto-defaulted: the empty value renders as an
 * explicit "-- Select surcharge tax treatment --" placeholder, and the
 * backend model (Two\Gateway\Model\Config\Backend\SurchargeTaxClass)
 * blocks the config save while surcharges are enabled and no real
 * treatment has been chosen. This is a deliberate cross-platform rule
 * (WooCommerce / PrestaShop / Magento): tax treatment is a merchant
 * decision, not something the plugin guesses from store defaults.
 *
 * The "Custom" option is a pure backward-compat carve-out for the
 * deprecated flat-rate field (custom_surcharge_tax_rate, stored at
 * config key `surcharge_tax_rate`). It is offered ONLY when that
 * legacy config value genuinely exists — a configured rate of 0 or
 * "0.00" is still a real value and still surfaces the option (hence
 * the explicit null/'' check, never a truthy check). Fresh installs
 * and merchants who never used the flat rate can never select (or
 * create) a custom rate.
 *
 * The delegate's option list includes "None" (value 0) plus every
 * Product Tax Class; selecting a class routes surcharge tax through
 * TaxCalculationInterface with full destination/rule resolution.
 *
 * Core's "None" (Product Tax Class id 0) is SUPPRESSED for new
 * selections (TWO-25279). It is a platform default, not a tax rule the
 * merchant set up, and picking it silently means "the surcharge is
 * never taxed, in any jurisdiction" — a tax decision the merchant
 * never made explicitly. Same rule across WooCommerce / PrestaShop /
 * Magento: a never-taxed treatment must be a tax rule the merchant
 * built, not an option we hand them.
 *
 * The stored-value carve-out below is the ONLY reason "None" ever appears.
 * There is deliberately no "but the store has no Product Tax Classes at
 * all" exemption: this list and
 * Two\Gateway\Model\Config\Backend\SurchargeTaxClass must offer and
 * accept exactly the same set, or the form renders an option the save
 * refuses. A store in that state disables the Surcharge Method, saves,
 * creates a Tax Rule, and re-enables — the same route it takes to pick any
 * treatment at all.
 *
 * It is still offered when it is ALREADY the stored value at the scope
 * being edited, and that carve-out is load-bearing rather than
 * cosmetic: an HTML select cannot render a value that is absent from
 * its option list, so it would fall back to the placeholder, and the
 * next admin save would post '' — which
 * Two\Gateway\Model\Config\Backend\AbstractSurchargeTreatmentGuard
 * rejects with a LocalizedException. Because Magento rolls the whole
 * section save back as one transaction, that would lock the merchant
 * out of saving ANY field in the payment section, not just this one.
 */
class SurchargeTaxClass implements OptionSourceInterface
{
    /**
     * Stored value of the legacy flat-rate treatment. Non-numeric on
     * purpose: Repository::getSurchargeTaxClassId() maps it (and the
     * unselected '') to null so it can never be int-cast into class id
     * 0, which would silently mean "None" (untaxed).
     */
    public const CUSTOM = 'custom';

    /**
     * Core's never-taxed Product Tax Class ("None"), the value
     * ProductTaxClassSource::getAllOptions(true) prepends. Compared as a
     * string because the delegate emits it as the string '0' and PHP's
     * loose comparison would equate it with any non-numeric label.
     */
    public const NEVER_TAXED_CLASS_ID = '0';

    /**
     * @var ProductTaxClassSource
     */
    private $productTaxClassSource;

    /**
     * @var ConfigRepository
     */
    private $configRepository;

    /**
     * @var RequestInterface
     */
    private $request;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    public function __construct(
        ProductTaxClassSource $productTaxClassSource,
        ConfigRepository $configRepository,
        RequestInterface $request,
        StoreManagerInterface $storeManager
    ) {
        $this->productTaxClassSource = $productTaxClassSource;
        $this->configRepository = $configRepository;
        $this->request = $request;
        $this->storeManager = $storeManager;
    }

    /**
     * @inheritDoc
     */
    public function toOptionArray(): array
    {
        // One resolution for the whole call: both carve-outs below must
        // reflect the same config scope the admin form is editing.
        [$scope, $scopeId] = $this->resolveConfigScope();

        $options = [
            ['value' => '', 'label' => __('-- Select surcharge tax treatment --')],
        ];
        if ($this->configRepository->hasCustomSurchargeTaxRateAtScope($scope, $scopeId)) {
            $options[] = ['value' => self::CUSTOM, 'label' => __('Custom flat rate (deprecated)')];
        }

        // getSurchargeTaxClassIdAtScope() maps '' / unset / 'custom' / any
        // non-numeric token to null, so an identity check against 0 is
        // true only when core's "None" is genuinely the stored value.
        $neverTaxedIsStored =
            $this->configRepository->getSurchargeTaxClassIdAtScope($scope, $scopeId) === 0;

        foreach ($this->productTaxClassSource->getAllOptions(true) as $option) {
            $isNeverTaxed = isset($option['value'])
                && (string)$option['value'] === self::NEVER_TAXED_CLASS_ID;
            if ($isNeverTaxed && !$neverTaxedIsStored) {
                continue;
            }
            // Emitted verbatim when it survives, so a re-offered "None"
            // keeps core's own translated label rather than a second,
            // divergent spelling of it.
            $options[] = $option;
        }
        return $options;
    }

    /**
     * Resolve the config scope the admin form is editing, so both carve-outs
     * reflect the value that scope's select will actually render.
     *
     * Anchored to the scope, NOT to a representative store view. Resolving a
     * website scope through its default store view reads the STORE row and
     * so returns a deeper override — with `websites/1 = 0` and
     * `stores/1 = 2` the website form would read 2, suppress "None", render
     * the placeholder over a stored 0, and the next save would post '' and
     * be rejected: exactly the lockout the carve-out exists to prevent.
     * Likewise a store id of null does NOT mean default scope —
     * ScopeConfigInterface resolves it as the CURRENT store — so the default
     * scope has to be named explicitly.
     *
     * The reads stay inheritance-aware (a store view showing an inherited
     * "None" must still offer it); they are merely anchored one level at a
     * time rather than always at the bottom.
     *
     * @return array{0: string, 1: int|null} [scope type, scope id]
     */
    private function resolveConfigScope(): array
    {
        try {
            $storeCode = $this->request->getParam('store');
            if ($storeCode) {
                return [
                    ScopeInterface::SCOPE_STORE,
                    (int)$this->storeManager->getStore($storeCode)->getId(),
                ];
            }
            $websiteCode = $this->request->getParam('website');
            if ($websiteCode) {
                return [
                    ScopeInterface::SCOPE_WEBSITE,
                    (int)$this->storeManager->getWebsite($websiteCode)->getId(),
                ];
            }
        } catch (\Exception $e) {
            return [ScopeConfigInterface::SCOPE_TYPE_DEFAULT, null];
        }
        return [ScopeConfigInterface::SCOPE_TYPE_DEFAULT, null];
    }
}
