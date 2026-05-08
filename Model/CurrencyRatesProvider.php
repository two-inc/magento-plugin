<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Model;

use Magento\Directory\Model\CurrencyFactory;
use Magento\Store\Model\StoreManagerInterface;
use Two\Gateway\Api\CurrencyRatesProviderInterface;

/**
 * Reads currency exchange rates via the store's base-currency rate table.
 *
 * This is the single class in the plugin permitted to call
 * Currency::load(); the phpstan service-contract rule is suppressed here
 * and here only. All other callers depend on
 * {@see CurrencyRatesProviderInterface}.
 */
class CurrencyRatesProvider implements CurrencyRatesProviderInterface
{
    /** @var CurrencyFactory */
    private $currencyFactory;

    /** @var StoreManagerInterface */
    private $storeManager;

    public function __construct(
        CurrencyFactory $currencyFactory,
        StoreManagerInterface $storeManager
    ) {
        $this->currencyFactory = $currencyFactory;
        $this->storeManager = $storeManager;
    }

    /**
     * @inheritDoc
     */
    public function getRate(string $fromCurrency, string $toCurrency, ?int $storeId = null): ?float
    {
        if ($fromCurrency === $toCurrency) {
            return 1.0;
        }

        $baseCurrency = $this->resolveBaseCurrency($storeId);
        $base = $this->loadBaseCurrency($baseCurrency);
        if ($base === null) {
            return null;
        }

        if ($fromCurrency === $baseCurrency) {
            $rate = (float)$base->getRate($toCurrency);
            return $rate > 0 ? $rate : null;
        }
        if ($toCurrency === $baseCurrency) {
            $rate = (float)$base->getRate($fromCurrency);
            return $rate > 0 ? 1.0 / $rate : null;
        }

        $rateFrom = (float)$base->getRate($fromCurrency);
        $rateTo = (float)$base->getRate($toCurrency);
        return ($rateFrom > 0 && $rateTo > 0) ? ($rateTo / $rateFrom) : null;
    }

    private function resolveBaseCurrency(?int $storeId): string
    {
        try {
            return (string)$this->storeManager->getStore($storeId)->getBaseCurrencyCode();
        } catch (\Exception $e) {
            return '';
        }
    }

    /**
     * Load the base currency's rate table. Confined to this method so the
     * phpstan suppression scope stays minimal.
     *
     * @return \Magento\Directory\Model\Currency|null
     */
    private function loadBaseCurrency(string $baseCurrency)
    {
        if ($baseCurrency === '') {
            return null;
        }
        try {
            return $this->currencyFactory->create()->load($baseCurrency);
        } catch (\Exception $e) {
            return null;
        }
    }
}
