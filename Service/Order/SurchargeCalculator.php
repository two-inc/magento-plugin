<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Service\Order;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Serialize\Serializer\Json;
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
     * Decimal places `cap` and `surcharge` are rounded to before the request.
     * The API refuses anything finer rather than rounding it itself. Scoped
     * to those two members deliberately — `gross_amount` and `rounding.step`
     * are not rounded here.
     */
    private const MONEY_DECIMALS = 2;

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

    private const CACHE_KEY_PREFIX = 'two_gateway_surcharge_';

    /**
     * Safety-net TTL for the cross-request cache below: the cache key
     * already changes with anything that would change the quote (cart
     * total, currency, buyer country, term, the merchant's surcharge
     * config), so this only bounds a quote drifting from something the
     * request body can't see (e.g. the backend's own FX rate), matching
     * RecordProvider::CACHE_LIFETIME's role for the same class of risk.
     */
    private const CACHE_LIFETIME = 300;

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
     * @var CacheInterface
     */
    private $cache;

    /**
     * @var Json
     */
    private $json;

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
        CurrencyRatesProviderInterface $ratesProvider,
        CacheInterface $cache,
        Json $json
    ) {
        $this->configRepository = $configRepository;
        $this->apiAdapter = $apiAdapter;
        $this->logRepository = $logRepository;
        $this->ratesProvider = $ratesProvider;
        $this->cache = $cache;
        $this->json = $json;
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
        $request = [
            'buyer_country_code' => $buyerCountry,
            'approved_on_recourse' => false,
            'currency' => $orderCurrency,
            'gross_amount' => $grossAmount,
            'order_terms' => $this->buildOrderTerms($selectedTermDays, $storeId),
            'buyer_fee_share' => $buyerFeeShare,
        ];

        // Cross-request cache, keyed on the exact outgoing request body
        // (plus store, since the same request on two stores is still the
        // same question to the pricing API): the cart total, currency,
        // buyer country, term and the merchant's surcharge config
        // (already folded into buyer_fee_share/order_terms) all fall out
        // of the key naturally, so any of them changing is a cache miss —
        // see CACHE_LIFETIME's doc comment for why a TTL sits underneath
        // this anyway. Only a successful quote is persisted; a failure
        // stays request-scoped (thrown below, never reaching this cache)
        // so a flapping API is retried on the next request, not
        // remembered as an error for CACHE_LIFETIME.
        $crossRequestCacheKey = self::CACHE_KEY_PREFIX . hash('sha256', serialize([$request, $storeId]));
        $cached = $this->cache->load($crossRequestCacheKey);
        if ($cached !== false) {
            return $this->responseCache[$cacheKey] = $this->json->unserialize($cached);
        }

        $response = $this->apiAdapter->execute('/v1/pricing/order/fee', $request, 'POST', $storeId);

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

        $result = [
            'amount' => $surcharge,
            'tax_rate' => $this->configRepository->getCustomSurchargeTaxRate($storeId),
            'description' => (string)__($descriptionTemplate, $selectedTermDays),
        ];
        $this->cache->save($this->json->serialize($result), $crossRequestCacheKey, [], self::CACHE_LIFETIME);
        return $this->responseCache[$cacheKey] = $result;
    }

    /**
     * TWO-25503: whether every FX conversion the surcharge could need for an
     * order in $orderCurrency is currently resolvable.
     *
     * A missing rate used to reach the buyer as a checkout-blocking
     * LocalizedException out of convertAmount(), from inside the totals
     * collector — so the whole checkout errored on every collectTotals() with
     * the method already selected, with nothing the buyer could do about it.
     * The correct stance is the one isAvailable() takes for an unprojectable
     * platform minimum: fail closed on THIS payment method only and let the
     * buyer use another. Callers use this to make that decision before any
     * conversion is attempted.
     *
     * Config-only and rate-lookup-only — deliberately no pricing API call, so
     * it is cheap enough for isAvailable(), which runs on every render of the
     * payment-method list.
     *
     * A conversion is only NEEDED when some term actually carries a non-zero
     * fixed amount or cap: convertAmount() short-circuits a zero, and the rate
     * is per currency PAIR, so one lookup answers for every term.
     */
    public function isSurchargeResolvable(string $orderCurrency, ?int $storeId = null): bool
    {
        $surchargeType = $this->configRepository->getSurchargeType($storeId);
        if ($surchargeType === SurchargeType::NONE) {
            return true;
        }

        $fixedCurrency = $this->configRepository->getSurchargeFixedCurrency($storeId);
        if ($fixedCurrency === '' || $fixedCurrency === $orderCurrency) {
            return true;
        }

        $hasPercentage = in_array($surchargeType, [SurchargeType::PERCENTAGE, SurchargeType::FIXED_AND_PERCENTAGE]);
        $hasFixed = in_array($surchargeType, [SurchargeType::FIXED, SurchargeType::FIXED_AND_PERCENTAGE]);

        $needsConversion = false;
        foreach ($this->configRepository->getAllBuyerTerms($storeId) as $days) {
            $config = $this->configRepository->getSurchargeConfig((int)$days, $storeId);
            if ($hasFixed && (float)$config['fixed'] !== 0.0) {
                $needsConversion = true;
                break;
            }
            if ($hasPercentage && $config['limit'] !== null && (float)$config['limit'] !== 0.0) {
                $needsConversion = true;
                break;
            }
        }
        if (!$needsConversion) {
            return true;
        }

        if ($this->ratesProvider->getRate($fixedCurrency, $orderCurrency, $storeId) !== null) {
            return true;
        }

        $this->logRepository->addErrorLog('Surcharge unresolvable: no FX rate available', [
            'from_currency' => $fixedCurrency,
            'to_currency' => $orderCurrency,
            'store_id' => $storeId,
        ]);
        return false;
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
        // Do NOT reintroduce a runtime guard here. TWO-25269 briefly threw on
        // a zero cap, on the premise that `cap => 0.0` read as "no cap"
        // downstream and would relay an uncapped percentage. That premise was
        // false — a zero cap bounds the fee at zero, it never uncaps it — and
        // this path stays faithful to whatever is configured.
        //
        // Separately, TWO-25289 stopped a zero limit being CONFIGURABLE in
        // the first place (Model\Config\Backend\SurchargeGrid::validateValue).
        // That is not the reverted guard returning under another name: it is an
        // admin-boundary decision, not a runtime one, and its reason is
        // different. A merchant who wants no fee on a term says so directly
        // with 0% and 0 fixed, so a zero limit has no legitimate use; and on
        // the sibling plugins a zero cap was being normalised to ABSENT and
        // relayed genuinely uncapped, which overcharges the buyer. Refusing it
        // at entry closes that across all three plugins. Zero remains valid
        // and faithfully relayed here for anything already stored.
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
     * The result is rounded to two decimal places (TWO-25289). The API
     * rejects monetary values finer than that rather than rounding them, so
     * an unrounded conversion (e.g. 349 * 0.0872) used to be refused
     * upstream and reach the buyer as the generic "temporarily unavailable"
     * error.
     *
     * Plain half-up rounding is deliberate. The grid refuses any limit a
     * merchant could CONFIGURE that would round away — not just an explicit
     * 0 but anything under half a cent
     * (Model\Config\Backend\SurchargeGrid::validateValue) — so the rounding
     * direction cannot decide whether a configured cap survives. What remains
     * is an FX conversion landing under half a cent, which does collapse to
     * 0.00 and therefore suppresses the fee. That is accepted, not
     * overlooked: sub-cent caps and away-from-zero rounding are out of scope,
     * and the case is pinned by
     * testASubCentCapRoundsDownToZeroWhichIsAcceptedScope.
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
            // Still rounded: an admin can type more precision than the API
            // accepts, so the no-conversion path needs the same 2dp gate as
            // the converted one.
            return round($amount, self::MONEY_DECIMALS);
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

        return round($amount * $rate, self::MONEY_DECIMALS);
    }
}
