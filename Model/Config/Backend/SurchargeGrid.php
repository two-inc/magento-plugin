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
use Two\Gateway\Model\Config\Source\SurchargeType;
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

    /**
     * Decimal places the pricing request is rounded to before it is sent
     * (SurchargeCalculator::MONEY_DECIMALS). Mirrored here because a limit is
     * refused when it rounds away at that precision, not merely when it is
     * typed as an exact zero.
     */
    private const MONEY_DECIMALS = 2;

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
        // runtime, which is the store-scope orphaned-override root cause.
        // The flag rides inside
        // [value] (not Magento's native [inherit]) so this afterSave still
        // runs and can do the purge itself.
        if (!empty($gridValues['__inherit'])) {
            $this->deleteScopeCells($scope, $scopeId);
            return parent::afterSave();
        }
        unset($gridValues['__inherit']);

        $maxFixed = $this->getConvertedFixedMax($scope, $scopeId);
        $maxPercentage = ConfigRepository::SURCHARGE_PERCENTAGE_MAX;
        // Whether the Limit column is VISIBLE for the surcharge type being
        // saved. It is shown only alongside a percentage, and the grid JS hides
        // it otherwise — but a hidden input still posts, so a limit stored
        // while the type was percentage keeps arriving after the merchant
        // switches away. Rejecting a zero there would fail the whole section
        // save over a cell the admin can neither see nor clear, which is the
        // dead end the funding-partner cap comment below already warns about,
        // so the zero rule is SKIPPED while the column is hidden.
        //
        // Skipped, not deleted. Deleting would discard a VALID limit on any
        // save made while the surcharge is fixed-only or off — a normal round
        // trip — while the equally inapplicable percentage cell survives it,
        // and at a non-default scope deleting an override does not retire a
        // value at all: it re-exposes the parent's. A legacy zero simply
        // surfaces again when the column comes back into view, which is where
        // the admin can act on it.
        $limitColumnVisible = $this->savedSurchargeTypeHasPercentage($groups, $scope, $scopeId);

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

                $this->validateValue($type, $value, $days, $maxFixed, $maxPercentage, $limitColumnVisible);

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
     * Whether the surcharge type being saved carries a percentage component,
     * i.e. whether the grid's Limit column is visible. Read from the POSTed
     * group first — the type and the grid are saved in the same request, so
     * the stored value is the PREVIOUS one and would misjudge a merchant
     * switching type.
     *
     * The config fallback is NOT an edge case: when the type field is left on
     * "Use Default Value" its `<select>` is rendered disabled, browsers do not
     * submit disabled inputs, and so nothing is posted for it. It is therefore
     * resolved AT THE SAVING SCOPE — an unscoped read returns the default
     * scope's value, which is the wrong answer for exactly the store that
     * inherits a different one.
     *
     * @param array<string, mixed> $groups
     */
    private function savedSurchargeTypeHasPercentage(array $groups, string $scope, int $scopeId): bool
    {
        $posted = $groups['payment_terms']['fields']['surcharge_type']['value'] ?? null;
        if (is_string($posted) && $posted !== '') {
            $type = $posted;
        } else {
            $path = sprintf('payment/%s/surcharge_type', $this->methodCode());
            $type = $scope === 'default'
                ? (string)$this->_config->getValue($path)
                : (string)$this->_config->getValue($path, $scope, $scopeId);
        }

        return in_array($type, [SurchargeType::PERCENTAGE, SurchargeType::FIXED_AND_PERCENTAGE], true);
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
     * Validate one surcharge cell, as POSTed (a string).
     *
     * Takes the RAW string rather than a cast float so it can tell 'abc' —
     * which casts to 0.0 — from a real zero, and report each on its own
     * terms. Nothing checked numeric input server-side before: the grid JS
     * does, but the direct-POST paths this backend exists to cover (curl,
     * app:config:import, a scripted config:set chain) skip it entirely.
     *
     * Note the caller has already returned for an EMPTY cell (it deletes
     * the config row instead), so `limit` only reaches here when the admin
     * typed something. Empty and zero are therefore distinguishable: empty
     * means "no limit", zero is refused outright (TWO-25289).
     *
     * @throws LocalizedException
     */
    private function validateValue(
        string $type,
        string $rawValue,
        int $days,
        ?int $maxFixed,
        int $maxPercentage,
        bool $limitColumnVisible = true
    ): void {
        if (!is_numeric($rawValue)) {
            throw new LocalizedException(
                __('%1 days - %2: value must be a number.', $days, $type)
            );
        }
        // is_numeric('1e400') is true and the cast is INF. `limit` is the one
        // column with no upper bound, so INF would be stored and then fail the
        // pricing request at json_encode time, far from the cause.
        if (!is_finite((float)$rawValue)) {
            throw new LocalizedException(
                __('%1 days - %2: value must be a number.', $days, $type)
            );
        }
        $value = (float)$rawValue;
        if ($value < 0) {
            throw new LocalizedException(
                __('%1 days - %2: value cannot be negative.', $days, $type)
            );
        }
        // A limit of exactly 0 is refused at entry. It is never what an
        // admin means: the limit bounds the WHOLE fee line — the percentage
        // part and the fixed amount together, not the percentage alone — so
        // a limit of 0 silently wipes the fixed amount as well, and nothing
        // in the grid says so. The intent it is mistaken for
        // ("charge nothing on this term") is expressible directly, by
        // entering 0 in both the fixed and percentage cells. An EMPTY limit
        // is a wholly legitimate configuration meaning "no limit" and is
        // never rejected — absence and zero are different values.
        //
        // round() first, not `=== 0.0`: SurchargeCalculator::convertAmount()
        // rounds the limit to 2dp before sending it, so a sub-cent limit
        // (0.001) would pass an exact-zero check and then arrive as a hard
        // cap of 0.00 — the very outcome being refused, one step later.
        // Refusing everything that rounds away is what makes "the rounding
        // direction cannot decide whether a configured cap survives" true.
        if ($type === 'limit' && $limitColumnVisible && round($value, self::MONEY_DECIMALS) === 0.0) {
            throw new LocalizedException(
                __(
                    '%1 days - limit: a limit of 0 is not allowed. To charge nothing on this term,'
                    . ' set the fixed amount and percentage to 0 instead, and leave the limit empty.',
                    $days
                )
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
