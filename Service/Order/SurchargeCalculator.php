<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Service\Order;

use Magento\Framework\Exception\LocalizedException;
use Two\Gateway\Api\Config\RepositoryInterface as ConfigRepository;
use Two\Gateway\Api\CurrencyRatesProviderInterface;
use Two\Gateway\Api\Log\RepositoryInterface as LogRepository;
use Two\Gateway\Model\Config\Source\RoundingBasis;
use Two\Gateway\Model\Config\Source\SurchargeType;
use Two\Gateway\Service\Api\Adapter;

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
     * Maps the merchant's rounding-basis config value to the pricing API's
     * rounding basis enum. A value absent from this map (i.e. "none") means
     * no rounding block is sent.
     */
    private const ROUNDING_BASIS_TO_API = [
        RoundingBasis::UP => 'UP',
        RoundingBasis::DOWN => 'DOWN',
        RoundingBasis::STANDARD => 'STANDARD',
    ];

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
     * @throws LocalizedException when no FX rate is resolvable for the pair, or when
     *         the API response is malformed or quotes a currency other than the order's
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

        // `http_status` may be set on success too (observability convenience);
        // gate on the actual 4xx/5xx range plus presence of `error_code`.
        $httpStatus = $response['http_status'] ?? null;
        if (($httpStatus !== null && $httpStatus >= 400) || isset($response['error_code'])) {
            $reason = $response['error_message'] ?? $response['error_details'] ?? 'Unknown error';
            $traceId = $response['error_trace_id'] ?? null;
            // Log full diagnostic details for ops; do NOT leak HTTP status or
            // upstream error reasons (e.g. "X-API-Key expired") to end users.
            $this->logRepository->addDebugLog('Pricing API upstream error', [
                'http_status' => $httpStatus,
                'error_code'  => $response['error_code'] ?? null,
                'reason'      => $reason,
                'trace_id'    => $traceId,
            ]);
            throw new LocalizedException(
                $traceId
                    ? __('Two payment is temporarily unavailable. Please try another payment method or contact support (ref: %1).', $traceId)
                    : __('Two payment is temporarily unavailable. Please try another payment method or contact support.')
            );
        }

        if (!isset($response['buyer_fee_share'])) {
            $this->logRepository->addErrorLog('Pricing API response missing buyer_fee_share', [
                'selected_term' => $selectedTermDays,
                'order_currency' => $orderCurrency,
            ]);
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
            $this->logRepository->addErrorLog('Pricing API returned a mismatched currency', [
                'selected_term' => $selectedTermDays,
                'response_currency' => $respCurrency,
                'order_currency' => $orderCurrency,
            ]);
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
            'tax_rate' => $this->configRepository->getCustomSurchargeTaxRate($storeId),
            'description' => (string)__($descriptionTemplate, $selectedTermDays),
        ];
    }

    /**
     * Build the buyer_fee_share block for the pricing request.
     *
     * Maps merchant config to the API schema:
     *  - percentage types supply `percentage`
     *  - fixed types supply `surcharge` (FX-converted to order currency)
     *  - a non-null limit supplies `cap` (FX-converted to order currency);
     *    an ABSENT limit is a legitimate "no cap" configuration and sends an
     *    uncapped percentage
     *  - a rounding basis + step supplies `rounding` (percentage modes only)
     *  - differential mode supplies `reference_terms` so the API computes
     *    the threshold itself — no delta math in the plugin
     *  - `surcharge_basis` is sent explicitly for clarity
     *
     * @return array<string, mixed>
     * @throws LocalizedException when the FX rate is missing
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
            $payload['surcharge'] = $this->convertAmount(
                (float)$config['fixed'],
                $fixedCurrency,
                $orderCurrency,
                $storeId,
                $selectedTermDays
            );
        }

        // `cap` only applies where the fee has a percentage component. The admin
        // grid exposes the Limit field for the percentage and fixed_and_percentage
        // types only (a fixed-only fee is constant — there is nothing to clamp), so
        // a stored limit left over from a previous surcharge type must not leak into
        // a fixed-only request and clamp the fee.
        //
        // Distinguish ABSENT from ZERO, and send both through faithfully:
        //  - an ABSENT limit (null) is a legitimate "no cap" configuration: omit
        //    `cap` entirely and the percentage is applied uncapped;
        //  - a limit of exactly 0 caps the fee at nothing, i.e. no surcharge is
        //    applied, and `cap => 0.0` is how the API is told that.
        //
        // TWO-25269 briefly threw here on a zero cap, on the premise that
        // `cap => 0.0` read as "no cap" downstream and would relay an uncapped
        // percentage. That premise was false: a zero cap clamps the fee to
        // zero, it never uncaps it. Do not reintroduce the guard, and do not
        // add admin validation rejecting a typed 0 — 0 is a valid setting.
        if ($hasPercentage && $config['limit'] !== null) {
            $payload['cap'] = $this->convertAmount(
                (float)$config['limit'],
                $fixedCurrency,
                $orderCurrency,
                $storeId,
                $selectedTermDays
            );
        }

        // `rounding` snaps the final buyer line item to a clean increment, computed
        // server-side. Like `cap`, the admin only exposes the controls for the
        // percentage and fixed_and_percentage types (a fixed-only fee is constant —
        // there is nothing to snap), so the $hasPercentage gate stops a stored basis/
        // step left over from a previous surcharge type leaking into a fixed-only
        // request.
        if ($hasPercentage) {
            $rounding = $this->buildRounding($storeId);
            if ($rounding !== null) {
                $payload['rounding'] = $rounding;
            }
        }

        if ($this->configRepository->isSurchargeDifferential($storeId)) {
            $defaultDays = $this->configRepository->getDefaultPaymentTerm($storeId);
            $payload['reference_terms'] = $this->buildOrderTerms($defaultDays, $storeId);
        }

        return $payload;
    }

    /**
     * Build the `rounding` block from merchant config, or null when rounding
     * is off (basis "none") or the step is not a positive number.
     *
     * The pricing API requires both step and basis when the block is present
     * and rejects a step <= 0, so an unconfigured/zero step omits the block
     * entirely rather than sending an invalid request.
     *
     * @return array{step: float, basis: string}|null
     */
    private function buildRounding(?int $storeId): ?array
    {
        $basis = $this->configRepository->getSurchargeRoundingBasis($storeId);
        if (!isset(self::ROUNDING_BASIS_TO_API[$basis])) {
            return null;
        }

        $step = $this->configRepository->getSurchargeRoundingStep($storeId);
        if ($step <= 0.0) {
            return null;
        }

        return ['step' => $step, 'basis' => self::ROUNDING_BASIS_TO_API[$basis]];
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
     * The result is returned unrounded (`$amount * $rate`); no rounding is
     * applied here. Since CurrencyRatesProvider returns null rather than a
     * zero or negative rate, the conversion cannot turn a non-zero configured
     * amount into zero.
     *
     * KNOWN PRE-EXISTING GAP (not introduced by, and not fixed by, the
     * TWO-25269 revert): the unrounded result is sent as-is, but the API
     * rejects monetary values finer than two decimal places rather than
     * rounding them. A conversion landing on >2dp (e.g. 349 * 0.0872) is
     * therefore refused upstream and surfaces to the buyer as the generic
     * "temporarily unavailable" error. Both `cap` and `surcharge` need
     * rounding to 2dp at this boundary. When that fix lands it must round a
     * cap AWAY from zero (or special-case zero), because a `cap` of 0.0 is
     * meaningful — it suppresses the surcharge — so a 0.004 cap must not be
     * rounded down into it.
     *
     * @param int|null $selectedTermDays term the conversion is being made for,
     *                                   for the diagnostic log only
     * @throws LocalizedException when no FX rate is resolvable for the pair
     */
    private function convertAmount(
        float $amount,
        string $fromCurrency,
        string $toCurrency,
        ?int $storeId = null,
        ?int $selectedTermDays = null
    ): float {
        if ($amount === 0.0 || $fromCurrency === '' || $fromCurrency === $toCurrency) {
            return $amount;
        }

        $rate = $this->ratesProvider->getRate($fromCurrency, $toCurrency, $storeId);
        if ($rate === null) {
            // Fail closed — but never silently. Without this the buyer sees a
            // checkout error while ops and the merchant see nothing at all, so
            // a missing rate for a pair looks like an unexplained drop-off.
            $this->logRepository->addErrorLog('Surcharge FX conversion failed: no rate available', [
                'from_currency' => $fromCurrency,
                'to_currency' => $toCurrency,
                'selected_term' => $selectedTermDays,
                'amount' => $amount,
                'store_id' => $storeId,
            ]);
            throw new LocalizedException(
                __(
                    'Cannot convert surcharge from %1 to %2: no exchange rate is currently available.',
                    $fromCurrency,
                    $toCurrency
                )
            );
        }

        return $amount * $rate;
    }
}
