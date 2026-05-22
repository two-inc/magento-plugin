<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Model\Config\Backend;

use Magento\Framework\App\Config\Value;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\Context;
use Magento\Framework\Registry;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Store\Model\StoreManagerInterface;
use Two\Gateway\Api\BrandRegistryInterface;
use Two\Gateway\Api\Config\RepositoryInterface as ConfigRepository;
use Two\Gateway\Api\CurrencyRatesProviderInterface;

/**
 * Backend model for the surcharge grid.
 *
 * The grid renders multiple config fields as a single table. On save,
 * this model extracts the individual field values from the POST data
 * and writes them as flat keys to core_config_data.
 */
class SurchargeGrid extends Value
{
    private const FIELDS = ['fixed', 'percentage', 'limit'];

    /** @var WriterInterface */
    private $configWriter;

    /** @var StoreManagerInterface */
    private $storeManager;

    /** @var CurrencyRatesProviderInterface */
    private $ratesProvider;

    /** @var BrandRegistryInterface */
    private $brandRegistry;

    public function __construct(
        Context $context,
        Registry $registry,
        ScopeConfigInterface $config,
        TypeListInterface $cacheTypeList,
        WriterInterface $configWriter,
        StoreManagerInterface $storeManager,
        CurrencyRatesProviderInterface $ratesProvider,
        BrandRegistryInterface $brandRegistry,
        ?AbstractResource $resource = null,
        ?AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        parent::__construct($context, $registry, $config, $cacheTypeList, $resource, $resourceCollection, $data);
        $this->configWriter = $configWriter;
        $this->storeManager = $storeManager;
        $this->ratesProvider = $ratesProvider;
        $this->brandRegistry = $brandRegistry;
    }

    /**
     * @inheritDoc
     */
    public function beforeSave()
    {
        $this->setValue('');
        return parent::beforeSave();
    }

    /**
     * @inheritDoc
     */
    public function afterSave()
    {
        $groups = $this->getData('groups');
        if (!is_array($groups)
            || !isset($groups['payment_terms']['fields']['surcharge_grid']['value'])
        ) {
            return parent::afterSave();
        }

        $gridValues = $groups['payment_terms']['fields']['surcharge_grid']['value'];
        if (!is_array($gridValues)) {
            return parent::afterSave();
        }

        $inheritData = [];
        if (isset($groups['payment_terms']['fields']['surcharge_grid']['inherit'])) {
            $inheritData = $groups['payment_terms']['fields']['surcharge_grid']['inherit'];
        }

        $scope = $this->getScope();
        $scopeId = (int)$this->getScopeId();
        $maxFixed = $this->getConvertedFixedMax($scope, $scopeId);
        $maxPercentage = ConfigRepository::SURCHARGE_PERCENTAGE_MAX;

        foreach ($gridValues as $days => $fields) {
            if (!is_array($fields)) {
                continue;
            }
            $days = (int)$days;

            foreach ($fields as $type => $value) {
                if (!in_array($type, self::FIELDS, true)) {
                    continue;
                }

                $path = sprintf('payment/two_payment/surcharge_%d_%s', $days, $type);

                if (isset($inheritData[$days][$type]) && $inheritData[$days][$type]) {
                    $this->configWriter->delete($path, $scope, $scopeId);
                    continue;
                }

                $value = (string)$value;
                if ($value === '') {
                    $this->configWriter->delete($path, $scope, $scopeId);
                    continue;
                }

                $numericValue = (float)$value;
                $this->validateValue($type, $numericValue, $days, $maxFixed, $maxPercentage);

                $this->configWriter->save($path, $value, $scope, $scopeId);
            }
        }

        // Persist the base currency so fixed amounts remain meaningful
        $currencyCode = $this->resolveBaseCurrency($scope, $scopeId);
        $this->configWriter->save(
            ConfigRepository::XML_PATH_SURCHARGE_FIXED_CURRENCY,
            $currencyCode,
            $scope,
            $scopeId
        );

        return parent::afterSave();
    }

    /**
     * Get the base currency for the scope being saved.
     */
    private function resolveBaseCurrency(string $scope, int $scopeId): string
    {
        try {
            if ($scope === 'stores' && $scopeId > 0) {
                return $this->storeManager->getStore($scopeId)->getBaseCurrencyCode();
            }
            if ($scope === 'websites' && $scopeId > 0) {
                return $this->storeManager->getWebsite($scopeId)->getBaseCurrencyCode();
            }
        } catch (\Exception $e) {
            // Fall through to global default
        }
        return (string)$this->getFieldsetDataValue('currency/options/base')
            ?: (string)$this->_config->getValue('currency/options/base')
            ?: 'EUR';
    }

    /**
     * Get the fixed max converted to the store's base currency.
     */
    /**
     * Brand-defined fixed-fee max, converted into the merchant's base
     * currency. Returns null when the brand imposes no upper bound;
     * validateValue() must skip the upper-bound check in that case.
     */
    private function getConvertedFixedMax(string $scope, int $scopeId): ?int
    {
        $limit = $this->brandRegistry->getSurchargeFixedMax();
        if ($limit === null) {
            return null;
        }
        $limitAmount = (int)$limit['amount'];
        $limitCurrency = $limit['currency'];
        $baseCurrency = $this->resolveBaseCurrency($scope, $scopeId);

        if ($baseCurrency === $limitCurrency) {
            return $limitAmount;
        }

        $storeId = ($scope === 'stores' && $scopeId > 0) ? $scopeId : null;
        $rate = $this->ratesProvider->getRate($limitCurrency, $baseCurrency, $storeId);
        if ($rate !== null && $rate > 0) {
            return (int)ceil($limitAmount * $rate);
        }

        return $limitAmount;
    }

    /**
     * Validate a surcharge field value.
     *
     * @throws LocalizedException
     */
    private function validateValue(
        string $type,
        float $value,
        int $days,
        ?int $maxFixed,
        int $maxPercentage
    ): void {
        if ($value < 0) {
            throw new LocalizedException(
                __('%1 days - %2: value cannot be negative.', $days, $type)
            );
        }
        if ($type === 'fixed' && $maxFixed !== null && $value > $maxFixed) {
            throw new LocalizedException(
                __('%1 days - fixed amount: maximum is %2.', $days, $maxFixed)
            );
        }
        if ($type === 'percentage' && $value > $maxPercentage) {
            throw new LocalizedException(
                __('%1 days - percentage: maximum is %2.', $days, $maxPercentage)
            );
        }
    }
}
