<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace ABN\Gateway\Service\Order;

use Magento\Framework\Exception\LocalizedException;
use ABN\Gateway\Api\Config\RepositoryInterface as ConfigRepository;
use ABN\Gateway\Api\CurrencyRatesProviderInterface;
use ABN\Gateway\Api\Log\RepositoryInterface as LogRepository;
use ABN\Gateway\Model\Config\Source\SurchargeType;
use ABN\Gateway\Service\Api\Adapter;

/**
 * Resolves the buyer surcharge for a given order and selected term by
 * delegating all arithmetic to POST /v1/pricing/order/fee. The plugin
 * maps merchant config onto the request's buyer_fee_share block and
 * uses the response's buyer_fee_share field as the final surcharge.
 *
 * Differential pricing is expressed to the API via reference_terms;
 * the plugin never makes a second call to compute a delta.
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
     * @var CurrencyRatesProviderInterface
     */
    private $ratesProvider;

    /**
     * Request-scoped cache of resolved surcharges, keyed on the public
     * calculate() inputs. The pricing endpoint is side-effect-free and
     * callers (total collector, ConfigProvider, TermSelection) repeat
     * identical calls within a single request; the cache dedupes those.
     *
     * @var array<string, array{amount: float, tax_rate: float, description: string}>
     */
    private $responseCache = [];

    public function __construct(
        ConfigRepository $configRepository,
        Adapter $apiAdapter,
        LogRepository $logRepository,
        CurrencyRatesProviderInterface $ratesProvider
    ) {
        $this->configRepository = $configRepository;
        $this->apiAdapter = $apiAdapter;
        $this->logRepository = $logRepository;
        $this->ratesProvider = $ratesProvider;
    }

    /**
     * Resolve the buyer surcharge for a given order and selected term.
     *
     * @param float $grossAmount Order gross amount, in $orderCurrency
     * @param int $selectedTermDays Term the buyer selected
     * @param string $buyerCountry ISO Alpha-2 country code
     * @param string $orderCurrency ISO 4217 currency code of the order
     * @param int|null $storeId
     *
     * @return array{amount: float, tax_rate: float, description: string}
     * @throws LocalizedException when FX rate is missing or API response is malformed
     */
    public function calculate(
        float $grossAmount,
        int $selectedTermDays,
        string $buyerCountry,
        string $orderCurrency,
        ?int $storeId = null
    ): array {
        $cacheKey = md5(serialize([$grossAmount, $selectedTermDays, $buyerCountry, $orderCurrency, $storeId]));
        if (isset($this->responseCache[$cacheKey])) {
            return $this->responseCache[$cacheKey];
        }

        $surchargeType = $this->configRepository->getSurchargeType($storeId);

        if ($surchargeType === SurchargeType::NONE) {
            return $this->responseCache[$cacheKey] = ['amount' => 0.0, 'tax_rate' => 0.0, 'description' => ''];
        }

        $buyerFeeShare = $this->buildBuyerFeeShare($surchargeType, $selectedTermDays, $orderCurrency, $storeId);

        $response = $this->apiAdapter->execute('/v1/pricing/order/fee', [
            'buyer_country_code' => $buyerCountry,
            'approved_on_recourse' => false,
            'currency' => $orderCurrency,
            'gross_amount' => $grossAmount,
            'order_terms' => $this->buildOrderTerms($selectedTermDays, $storeId),
            'buyer_fee_share' => $buyerFeeShare,
        ]);

        if (!isset($response['buyer_fee_share'])) {
            throw new LocalizedException(
                __('Pricing API response missing required field: buyer_fee_share')
            );
        }

        $surcharge = (float)$response['buyer_fee_share'];

        // Guard against the API echoing a currency that doesn't match what we
        // sent — means our request was reinterpreted and the figure can't be
        // applied to the order without FX, which is the API's job not ours.
        $respCurrency = isset($response['currency']) ? (string)$response['currency'] : $orderCurrency;
        if ($respCurrency !== $orderCurrency) {
            throw new LocalizedException(
                __(
                    'Pricing API returned currency %1 but order currency is %2.',
                    $respCurrency,
                    $orderCurrency
                )
            );
        }

        $this->logRepository->addDebugLog('Surcharge resolved from API', [
            'selected_term' => $selectedTermDays,
            'surcharge_type' => $surchargeType,
            'buyer_fee_share_request' => $buyerFeeShare,
            'buyer_fee_share_response' => $surcharge,
            'order_currency' => $orderCurrency,
        ]);

        $descriptionTemplate = $this->configRepository->getSurchargeLineDescription($storeId);

        return $this->responseCache[$cacheKey] = [
            'amount' => $surcharge,
            'tax_rate' => $this->configRepository->getSurchargeTaxRate($storeId),
            'description' => (string)__($descriptionTemplate, $selectedTermDays),
        ];
    }

    /**
     * Build the buyer_fee_share block for the pricing request.
     *
     * Maps merchant config to the API schema:
     *  - percentage types supply `percentage`
     *  - fixed types supply `surcharge` (FX-converted to order currency)
     *  - limit > 0 supplies `cap` (FX-converted to order currency)
     *  - differential mode supplies `reference_terms` so the API computes
     *    the threshold itself — no delta math in the plugin
     *  - `surcharge_basis` is sent explicitly for clarity
     *
     * @return array<string, mixed>
     * @throws LocalizedException when FX rate is missing
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

        // API default is 100%; send 0 when the merchant hasn't opted into a percentage
        // so the fixed-only path doesn't accidentally pass the whole fee on.
        $payload = [
            'percentage' => $hasPercentage ? (float)$config['percentage'] : 0.0,
            'surcharge_basis' => 'buyer_pays',
        ];

        if ($hasFixed) {
            $payload['surcharge'] = $this->convertAmount((float)$config['fixed'], $fixedCurrency, $orderCurrency);
        }

        if ($config['limit'] !== null) {
            $payload['cap'] = $this->convertAmount((float)$config['limit'], $fixedCurrency, $orderCurrency);
        }

        if ($this->configRepository->isSurchargeDifferential($storeId)) {
            $defaultDays = $this->configRepository->getDefaultPaymentTerm($storeId);
            $payload['reference_terms'] = $this->buildOrderTerms($defaultDays, $storeId);
        }

        return $payload;
    }

    /**
     * Build an order_terms block matching the merchant's payment-terms type.
     *
     * @return array<string, mixed>
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
     * Convert an amount between currencies if needed.
     *
     * @throws LocalizedException if Magento has no exchange rate for the pair
     */
    private function convertAmount(float $amount, string $fromCurrency, string $toCurrency): float
    {
        if ($amount === 0.0 || $fromCurrency === '' || $fromCurrency === $toCurrency) {
            return $amount;
        }

        $rate = $this->ratesProvider->getRate($fromCurrency, $toCurrency);
        if ($rate === null) {
            throw new LocalizedException(
                __(
                    'Cannot convert surcharge from %1 to %2. '
                    . 'Please configure currency exchange rates under Stores > Currency Rates.',
                    $fromCurrency,
                    $toCurrency
                )
            );
        }

        return $amount * $rate;
    }
}
