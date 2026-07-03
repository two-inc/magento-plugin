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
use Magento\Framework\App\ResourceConnection;
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
use Two\Gateway\Service\Merchant\SettingsProvider;

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

    /** @var SettingsProvider */
    private $settingsProvider;

    /** @var ResourceConnection */
    private $resourceConnection;

    public function __construct(
        Context $context,
        Registry $registry,
        ScopeConfigInterface $config,
        TypeListInterface $cacheTypeList,
        WriterInterface $configWriter,
        StoreManagerInterface $storeManager,
        CurrencyRatesProviderInterface $ratesProvider,
        BrandRegistryInterface $brandRegistry,
        SettingsProvider $settingsProvider,
        ResourceConnection $resourceConnection,
        ?AbstractResource $resource = null,
        ?AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        parent::__construct($context, $registry, $config, $cacheTypeList, $resource, $resourceCollection, $data);
        $this->configWriter = $configWriter;
        $this->storeManager = $storeManager;
        $this->ratesProvider = $ratesProvider;
        $this->brandRegistry = $brandRegistry;
        $this->settingsProvider = $settingsProvider;
        $this->resourceConnection = $resourceConnection;
    }

    /**
     * Active payment-method code. Resolved at call time from the
     * brand registry so the same backend works for every brand
     * without a per-brand DI rebinding.
     */
    private function methodCode(): string
    {
        return $this->brandRegistry->getCode();
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

        $scope = $this->getScope();
        $scopeId = (int)$this->getScopeId();

        // Grid-level "Use Website/Default": a single checkbox inherits the
        // whole grid. Purge every per-term cell row at this scope so none
        // is left orphaned — invisible to the admin grid but still read at
        // runtime, which is the ABN-440 root cause. The flag rides inside
        // [value] (not Magento's native [inherit]) so this afterSave still
        // runs and can do the purge itself.
        if (!empty($gridValues['__inherit'])) {
            $this->deleteScopeCells($scope, $scopeId);
            return parent::afterSave();
        }
        unset($gridValues['__inherit']);

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

                $path = sprintf('payment/%s/surcharge_%d_%s', $this->methodCode(), $days, $type);

                $value = (string)$value;
                if ($value === '') {
                    $this->configWriter->delete($path, $scope, $scopeId);
                    continue;
                }

                // Accept the Dutch comma decimal separator. Front-end
                // JS already normalises on input, but admins posting
                // directly (curl, REST app:config:import, scripted
                // setup:config:set chain) hit this code path without
                // the JS pass; normalise server-side too.
                $value = str_replace(',', '.', $value);

                $numericValue = (float)$value;
                $this->validateValue($type, $numericValue, $days, $maxFixed, $maxPercentage);

                $this->configWriter->save($path, $value, $scope, $scopeId);
            }
        }

        // Persist the base currency so fixed amounts remain meaningful
        $currencyCode = $this->resolveBaseCurrency($scope, $scopeId);
        $this->configWriter->save(
            sprintf('payment/%s/surcharge_fixed_currency', $this->methodCode()),
            $currencyCode,
            $scope,
            $scopeId
        );

        return parent::afterSave();
    }

    /**
     * Delete every per-term surcharge cell row plus the base-currency
     * marker at the given scope. Used when the grid inherits, so an
     * inherited grid leaves no orphaned surcharge_* override behind.
     */
    private function deleteScopeCells(string $scope, int $scopeId): void
    {
        $conn = $this->resourceConnection->getConnection();
        $method = $this->methodCode();
        $paths = $conn->fetchCol(
            $conn->select()
                ->from($conn->getTableName('core_config_data'), 'path')
                ->where('scope = ?', $scope)
                ->where('scope_id = ?', $scopeId)
                ->where('path LIKE ?', 'payment/' . $method . '/surcharge%')
                ->where('path REGEXP ?', 'surcharge_[0-9]+_(fixed|percentage|limit)$')
        );
        $paths[] = sprintf('payment/%s/surcharge_fixed_currency', $method);
        foreach (array_unique($paths) as $path) {
            $this->configWriter->delete($path, $scope, $scopeId);
        }
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
     * Merchant's fixed-fee surcharge cap (from GET /v1/merchant),
     * converted into the merchant's base currency. Returns null when
     * there is no upper bound; validateValue() must skip the
     * upper-bound check in that case.
     */
    private function getConvertedFixedMax(string $scope, int $scopeId): ?int
    {
        $storeId = ($scope === 'stores' && $scopeId > 0) ? $scopeId : null;
        $limit = $this->settingsProvider->getSurchargeLimit($storeId);
        if ($limit === null) {
            return null;
        }
        $limitAmount = (int)$limit['amount'];
        $limitCurrency = $limit['currency'];
        $baseCurrency = $this->resolveBaseCurrency($scope, $scopeId);

        if ($baseCurrency === $limitCurrency) {
            return $limitAmount;
        }

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
