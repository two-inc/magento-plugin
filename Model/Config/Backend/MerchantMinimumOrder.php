<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Model\Config\Backend;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Value;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Framework\Registry;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Two\Gateway\Api\BrandRegistryInterface;
use Two\Gateway\Api\CurrencyRatesProviderInterface;

/**
 * Merchant-set minimum order value, interpreted in the STORE BASE
 * currency. The platform/partner minimum (the brand's minimum_order)
 * is the floor: a merchant may only RAISE the bar, never lower it
 * below what the funding setup requires. The floor is converted into
 * the store base currency for the comparison; when no exchange rate
 * is configured the numeric floor check is skipped here — the
 * checkout gate enforces both minima independently and fails closed.
 */
class MerchantMinimumOrder extends Value
{
    /**
     * @var BrandRegistryInterface
     */
    private $brandRegistry;

    /**
     * @var CurrencyRatesProviderInterface
     */
    private $ratesProvider;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var PriceCurrencyInterface
     */
    private $priceCurrency;

    public function __construct(
        Context $context,
        Registry $registry,
        ScopeConfigInterface $config,
        TypeListInterface $cacheTypeList,
        BrandRegistryInterface $brandRegistry,
        CurrencyRatesProviderInterface $ratesProvider,
        StoreManagerInterface $storeManager,
        PriceCurrencyInterface $priceCurrency,
        ?AbstractResource $resource = null,
        ?AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        $this->brandRegistry = $brandRegistry;
        $this->ratesProvider = $ratesProvider;
        $this->storeManager = $storeManager;
        $this->priceCurrency = $priceCurrency;
        parent::__construct($context, $registry, $config, $cacheTypeList, $resource, $resourceCollection, $data);
    }

    /**
     * @inheritDoc
     */
    public function beforeSave()
    {
        $value = trim((string)$this->getValue());
        if ($value === '') {
            return parent::beforeSave();
        }

        $normalised = str_replace(',', '.', $value);
        if (!is_numeric($normalised) || (float)$normalised < 0) {
            throw new LocalizedException(__('Minimum Order Value must be a non-negative number.'));
        }
        $this->setValue($normalised);

        $platformMinimum = $this->brandRegistry->getMinimumOrder();
        if ($platformMinimum === null) {
            return parent::beforeSave();
        }

        $store = $this->resolveScopeStore();
        $baseCurrency = $store !== null ? (string)$store->getBaseCurrencyCode() : '';
        $storeId = $store !== null ? (int)$store->getId() : null;

        $floor = $platformMinimum['amount'];
        if ($baseCurrency !== '' && $baseCurrency !== $platformMinimum['currency']) {
            $rate = $this->ratesProvider->getRate($platformMinimum['currency'], $baseCurrency, $storeId);
            if ($rate === null || $rate <= 0) {
                // Cannot express the floor in the store currency; accept the
                // value — the checkout gate enforces both minima independently.
                return parent::beforeSave();
            }
            $floor = round($floor * $rate, 2);
        }

        if ((float)$normalised <= $floor) {
            $floorDisplay = $this->priceCurrency->format(
                $floor,
                false,
                2,
                $store,
                $baseCurrency ?: $platformMinimum['currency']
            );
            $nativeDisplay = $this->priceCurrency->format(
                $platformMinimum['amount'],
                false,
                2,
                $store,
                $platformMinimum['currency']
            );
            throw new LocalizedException(__(
                'Minimum Order Value must exceed the platform minimum of %1, %2 tax.',
                $floorDisplay === $nativeDisplay ? $floorDisplay : sprintf('%s (%s)', $floorDisplay, $nativeDisplay),
                $platformMinimum['basis'] === 'gross' ? __('including') : __('excluding')
            ));
        }

        return parent::beforeSave();
    }

    /**
     * The store whose base currency the value is interpreted in, derived
     * from the config scope being saved (store view, website default
     * store, or the global default store).
     *
     * Counterpart: Comment\MerchantMinimumOrder::resolveScopeStore() resolves
     * the same scope from request params (the comment renders at page load,
     * before any model scope exists). Keep their store resolution in lockstep
     * or the displayed floor and the validated floor can disagree.
     *
     * @return \Magento\Store\Api\Data\StoreInterface|null
     */
    private function resolveScopeStore()
    {
        try {
            if ($this->getScope() === ScopeInterface::SCOPE_STORES) {
                return $this->storeManager->getStore($this->getScopeId());
            }
            if ($this->getScope() === ScopeInterface::SCOPE_WEBSITES) {
                $website = $this->storeManager->getWebsite($this->getScopeId());
                return $this->storeManager->getStore($website->getDefaultGroup()->getDefaultStoreId());
            }
            return $this->storeManager->getStore();
        } catch (\Exception $e) {
            return null;
        }
    }
}
