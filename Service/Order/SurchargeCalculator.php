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
 * Delegates surcharge calculation to the Two pricing API.
 *
 * Plugin maps merchant surcharge config to a buyer_fee_share object
 * on POST /v1/pricing/order/fee. The API applies percentage, fixed,
 * cap, and differential (via reference_terms) and returns the final
 * buyer-facing fee. Plugin does no fee arithmetic — it only
 * FX-converts merchant-config amounts into order currency before send.
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
     * @throws LocalizedException if fixed-fee currency conversion fails
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

        $isDifferential = $this->configRepository->isSurchargeDifferential($storeId);
        if ($isDifferential
            && $selectedTermDays === $this->configRepository->getDefaultPaymentTerm($storeId)
        ) {
            // Default term in differential mode — no surcharge, skip API call.
            return [
                'amount' => 0.0,
                'tax_rate' => $this->configRepository->getSurchargeTaxRate($storeId),
                'description' => $this->buildDescription($selectedTermDays, $storeId),
            ];
        }

        $feeShare = $this->buildBuyerFeeShare($surchargeType, $selectedTermDays, $orderCurrency, $storeId);
        $fee = $this->fetchBuyerFee(
            $grossAmount,
            $selectedTermDays,
            $buyerCountry,
            $orderCurrency,
            $feeShare,
            $storeId
        );

        $this->logRepository->addDebugLog('Surcharge calculated', [
            'selected_term' => $selectedTermDays,
            'surcharge_type' => $surchargeType,
            'buyer_fee_share' => $feeShare,
            'order_currency' => $orderCurrency,
            'result' => $fee,
        ]);

        return [
            'amount' => $fee,
            'tax_rate' => $this->configRepository->getSurchargeTaxRate($storeId),
            'description' => $this->buildDescription($selectedTermDays, $storeId),
        ];
    }

    /**
     * Build the buyer_fee_share payload from merchant config.
     *
     * Fixed and cap amounts are FX-converted from the merchant's configured
     * fixed_currency into the order currency before send. Percentage is
     * dimensionless. API applies percentage to its own fee base, adds the
     * (converted) fixed amount, then caps at the (converted) cap.
     */
    private function buildBuyerFeeShare(
        string $surchargeType,
        int $selectedTermDays,
        string $orderCurrency,
        ?int $storeId
    ): array {
        $config = $this->configRepository->getSurchargeConfig($selectedTermDays, $storeId);
        $fixedCurrency = $this->configRepository->getSurchargeFixedCurrency($storeId);

        $hasPercentage = in_array($surchargeType, [SurchargeType::PERCENTAGE, SurchargeType::FIXED_AND_PERCENTAGE]);
        $hasFixed = in_array($surchargeType, [SurchargeType::FIXED, SurchargeType::FIXED_AND_PERCENTAGE]);

        $share = [
            'surcharge_basis' => 'buyer_pays',
            'percentage' => $hasPercentage ? (float)$config['percentage'] : 0.0,
            'surcharge' => $hasFixed
                ? $this->convertAmount((float)$config['fixed'], $fixedCurrency, $orderCurrency)
                : 0.0,
        ];

        if ($config['limit'] !== null) {
            $share['cap'] = $this->convertAmount((float)$config['limit'], $fixedCurrency, $orderCurrency);
        }

        if ($this->configRepository->isSurchargeDifferential($storeId)) {
            $share['reference_terms'] = $this->buildOrderTerms(
                $this->configRepository->getDefaultPaymentTerm($storeId),
                $storeId
            );
        }

        return $share;
    }

    /**
     * Build the order_terms object shared between the top-level payload
     * and buyer_fee_share.reference_terms.
     */
    private function buildOrderTerms(int $durationDays, ?int $storeId): array
    {
        $terms = [
            'type' => 'NET_TERMS',
            'duration_days' => $durationDays,
        ];
        if ($this->configRepository->getPaymentTermsType($storeId) === 'end_of_month') {
            $terms['duration_days_calculated_from'] = 'END_OF_MONTH';
        }
        return $terms;
    }

    /**
     * Call the pricing API and return the authoritative buyer fee.
     *
     * Results are cached in memory for the current request so multiple
     * collectTotals() runs and chip-precompute loops don't redundantly
     * hit the API for the same term.
     */
    private function fetchBuyerFee(
        float $grossAmount,
        int $selectedTermDays,
        string $buyerCountry,
        string $orderCurrency,
        array $feeShare,
        ?int $storeId
    ): float {
        $cacheKey = sprintf(
            '%s|%d|%s|%s|%d|%s',
            $grossAmount,
            $selectedTermDays,
            $buyerCountry,
            $orderCurrency,
            (int)$storeId,
            md5(json_encode($feeShare) ?: '')
        );
        if (isset($this->feeCache[$cacheKey])) {
            return $this->feeCache[$cacheKey];
        }

        $response = $this->apiAdapter->execute('/v1/pricing/order/fee', [
            'buyer_country_code' => $buyerCountry,
            'approved_on_recourse' => false,
            'gross_amount' => $grossAmount,
            'currency' => $orderCurrency,
            'order_terms' => $this->buildOrderTerms($selectedTermDays, $storeId),
            'buyer_fee_share' => $feeShare,
        ]);

        $fee = (float)($response['buyer_fee_share'] ?? 0);
        $this->feeCache[$cacheKey] = $fee;
        return $fee;
    }

    private function buildDescription(int $selectedTermDays, ?int $storeId): string
    {
        return (string)__(
            '%1 - %2 days',
            $this->configRepository->getSurchargeLineDescription($storeId),
            $selectedTermDays
        );
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
