<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Model\Config\Source;

use Magento\Framework\App\RequestInterface;
use Magento\Framework\Data\OptionSourceInterface;
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
        $storeId = $this->resolveStoreId();

        $options = [
            ['value' => '', 'label' => __('-- Select surcharge tax treatment --')],
        ];
        if ($this->configRepository->hasCustomSurchargeTaxRate($storeId)) {
            $options[] = ['value' => self::CUSTOM, 'label' => __('Custom flat rate (deprecated)')];
        }

        // getSurchargeTaxClassId() maps '' / unset / 'custom' / any
        // non-numeric token to null, so an identity check against 0 is
        // true only when core's "None" is genuinely the stored value.
        $neverTaxedIsStored = $this->configRepository->getSurchargeTaxClassId($storeId) === 0;

        foreach ($this->productTaxClassSource->getAllOptions(true) as $option) {
            $isNeverTaxed = isset($option['value'])
                && (string)$option['value'] === self::NEVER_TAXED_CLASS_ID;
            if ($isNeverTaxed && !$neverTaxedIsStored) {
                continue;
            }
            // Emitted verbatim when it IS the stored value, so the
            // option keeps core's own translated "None" label rather
            // than a second, divergent spelling of it.
            $options[] = $option;
        }
        return $options;
    }

    /**
     * Resolve a store view representative of the config scope the
     * admin form is editing, so the "Custom" and never-taxed carve-outs
     * reflect the value the merchant would actually inherit at that
     * scope — a store view that inherits "None" from the default scope
     * must still render it. Website
     * scope resolves through the website's default store view (which
     * inherits website-scoped values); default scope (no scope params)
     * resolves to null.
     *
     * @return int|null
     */
    private function resolveStoreId(): ?int
    {
        try {
            $storeCode = $this->request->getParam('store');
            if ($storeCode) {
                return (int)$this->storeManager->getStore($storeCode)->getId();
            }
            $websiteCode = $this->request->getParam('website');
            if ($websiteCode) {
                $website = $this->storeManager->getWebsite($websiteCode);
                $group = $this->storeManager->getGroup($website->getDefaultGroupId());
                $storeId = (int)$group->getDefaultStoreId();
                return $storeId > 0 ? $storeId : null;
            }
        } catch (\Exception $e) {
            return null;
        }
        return null;
    }
}
