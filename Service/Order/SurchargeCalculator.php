<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Service\Order;

use Magento\Directory\Model\CurrencyFactory;
use Magento\Framework\Exception\LocalizedException;
use Two\Gateway\Api\Config\RepositoryInterface as ConfigRepository;
use Two\Gateway\Api\Log\RepositoryInterface as LogRepository;
use Two\Gateway\Model\Config\Source\SurchargeType;
use Two\Gateway\Service\Api\Adapter;

/**
 * Calculates buyer surcharge based on merchant config and API fee data.
 *
 * Flow:
 * 1. Fetch merchant fee from POST /v1/pricing/order/fee
 * 2. If differential mode, also fetch fee for default term and use delta
 * 3. Apply merchant's surcharge config (percentage, fixed, limit)
 * 4. Round up to next cent
 */
class SurchargeCalculator
{
    /**
     * @var ConfigRepository
     */
    private $configRepository;

    /**
     * @var Adapter
     */
    private $apiAdapter;

    /**
     * @var LogRepository
     */
    private $logRepository;

    /**
     * @var CurrencyFactory
     */
    private $currencyFactory;

    /**
     * @var array In-memory cache for pricing API responses, keyed on request params.
     */
    private $feeCache = [];

    public function __construct(
        ConfigRepository $configRepository,
        Adapter $apiAdapter,
        LogRepository $logRepository,
        CurrencyFactory $currencyFactory
    ) {
        $this->configRepository = $configRepository;
        $this->apiAdapter = $apiAdapter;
        $this->logRepository = $logRepository;
        $this->currencyFactory = $currencyFactory;
    }

    /**
     * Calculate the buyer's surcharge for a given order and selected term.
     *
     * @param float $grossAmount Order gross amount
     * @param int $selectedTermDays The term the buyer selected
     * @param string $buyerCountry ISO Alpha-2 country code
     * @param string $orderCurrency ISO currency code of the order
     * @param int|null $storeId
     *
     * @return array{amount: float, tax_rate: float, description: string}
     * @throws LocalizedException if fixed fee currency conversion fails
     */
    public function calculate(
        float $grossAmount,
        int $selectedTermDays,
        string $buyerCountry,
        string $orderCurrency,
        ?int $storeId = null
    ): array {
        $surchargeType = $this->configRepository->getSurchargeType($storeId);

        if ($surchargeType === SurchargeType::NONE) {
            return ['amount' => 0.0, 'tax_rate' => 0.0, 'description' => ''];
        }

        $feeBase = $this->getFeeBase($grossAmount, $selectedTermDays, $buyerCountry, $storeId);
        $config = $this->configRepository->getSurchargeConfig($selectedTermDays, $storeId);
        $fixedCurrency = $this->configRepository->getSurchargeFixedCurrency($storeId);

        $surcharge = 0.0;

        $hasPercentage = in_array($surchargeType, [SurchargeType::PERCENTAGE, SurchargeType::FIXED_AND_PERCENTAGE]);
        $hasFixed = in_array($surchargeType, [SurchargeType::FIXED, SurchargeType::FIXED_AND_PERCENTAGE]);

        if ($hasPercentage) {
            $surcharge += $feeBase * ($config['percentage'] / 100);
        }
        if ($hasFixed) {
            $fixedAmount = (float)$config['fixed'];
            $surcharge += $this->convertAmount($fixedAmount, $fixedCurrency, $orderCurrency);
        }

        // Apply limit cap (null = not set = no cap; 0 = explicit zero cap)
        $limit = $config['limit'];
        if ($limit !== null) {
            $limit = $this->convertAmount($limit, $fixedCurrency, $orderCurrency);
            if ($surcharge > $limit) {
                $surcharge = $limit;
            }
        }

        // Round up to next cent
        $surcharge = ceil($surcharge * 100) / 100;

        $this->logRepository->addDebugLog('Surcharge calculated', [
            'selected_term' => $selectedTermDays,
            'fee_base' => $feeBase,
            'surcharge_type' => $surchargeType,
            'config' => $config,
            'fixed_currency' => $fixedCurrency,
            'order_currency' => $orderCurrency,
            'result' => $surcharge,
        ]);

        return [
            'amount' => $surcharge,
            'tax_rate' => $this->configRepository->getSurchargeTaxRate($storeId),
            'description' => $this->configRepository->getSurchargeLineDescription($storeId),
        ];
    }

    /**
     * Get the fee base for surcharge calculation.
     * In differential mode, this is the delta between selected and default term fees.
     */
    private function getFeeBase(
        float $grossAmount,
        int $selectedTermDays,
        string $buyerCountry,
        ?int $storeId
    ): float {
        $selectedFee = $this->fetchMerchantFee($grossAmount, $selectedTermDays, $buyerCountry, $storeId);

        if (!$this->configRepository->isSurchargeDifferential($storeId)) {
            return $selectedFee;
        }

        $defaultDays = $this->configRepository->getDefaultPaymentTerm($storeId);
        if ($selectedTermDays === $defaultDays) {
            return 0.0; // No surcharge for default term in differential mode
        }

        $defaultFee = $this->fetchMerchantFee($grossAmount, $defaultDays, $buyerCountry, $storeId);
        return max(0.0, $selectedFee - $defaultFee);
    }

    /**
     * Call the pricing API to get the merchant's fee for a given term.
     *
     * Results are cached in memory for the current request so that
     * multiple collectTotals() calls don't produce redundant API hits.
     */
    private function fetchMerchantFee(
        float $grossAmount,
        int $durationDays,
        string $buyerCountry,
        ?int $storeId
    ): float {
        $cacheKey = sprintf('%s|%d|%s|%d', $grossAmount, $durationDays, $buyerCountry, (int)$storeId);
        if (isset($this->feeCache[$cacheKey])) {
            return $this->feeCache[$cacheKey];
        }

        $termsType = $this->configRepository->getPaymentTermsType($storeId);
        $orderTerms = [
            'type' => 'NET_TERMS',
            'duration_days' => $durationDays,
        ];
        if ($termsType === 'end_of_month') {
            $orderTerms['duration_days_calculated_from'] = 'END_OF_MONTH';
        }

        $response = $this->apiAdapter->execute('/v1/pricing/order/fee', [
            'buyer_country_code' => $buyerCountry,
            'approved_on_recourse' => false,
            'gross_amount' => $grossAmount,
            'order_terms' => $orderTerms,
        ]);

        $fee = (float)($response['total_fee'] ?? 0);
        $this->feeCache[$cacheKey] = $fee;
        return $fee;
    }

    /**
     * Convert an amount between currencies if needed.
     *
     * @throws LocalizedException if Magento has no exchange rate for the pair
     */
    private function convertAmount(float $amount, string $fromCurrency, string $toCurrency): float
    {
        if ($amount === 0.0 || $fromCurrency === '' || $fromCurrency === $toCurrency) {
            return $amount;
        }

        try {
            $currency = $this->currencyFactory->create()->load($fromCurrency);
            return (float)$currency->convert($amount, $toCurrency);
        } catch (\Exception $e) {
            throw new LocalizedException(
                __(
                    'Cannot convert surcharge from %1 to %2. '
                    . 'Please configure currency exchange rates under Stores > Currency Rates.',
                    $fromCurrency,
                    $toCurrency
                )
            );
        }
    }
}
