<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Service\Order;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\BrandRegistryInterface;
use Two\Gateway\Api\Config\RepositoryInterface as ConfigRepository;
use Two\Gateway\Api\CurrencyRatesProviderInterface;
use Two\Gateway\Api\Log\RepositoryInterface as LogRepository;
use Two\Gateway\Model\ApiTranslator\NullApiTranslator;
use Two\Gateway\Model\Config\Source\RoundingBasis;
use Two\Gateway\Model\Config\Source\SurchargeType;
use Two\Gateway\Service\Api\Adapter;
use Two\Gateway\Service\Merchant\SettingsProvider;
use Two\Gateway\Service\Merchant\SurchargeCapProvider;
use Two\Gateway\Service\Order\SurchargeCalculator;

class SurchargeCalculatorTest extends TestCase
{
    /** @var ConfigRepository|\PHPUnit\Framework\MockObject\MockObject */
    private $config;

    /** @var Adapter|\PHPUnit\Framework\MockObject\MockObject */
    private $adapter;

    /** @var CurrencyRatesProviderInterface|\PHPUnit\Framework\MockObject\MockObject */
    private $ratesProvider;

    /** @var LogRepository|\PHPUnit\Framework\MockObject\MockObject */
    private $log;

    /** @var CacheInterface|\PHPUnit\Framework\MockObject\MockObject */
    private $cache;

    /** @var SettingsProvider|\PHPUnit\Framework\MockObject\MockObject */
    private $settings;

    /** @var SurchargeCalculator */
    private $calculator;

    protected function setUp(): void
    {
        $this->config = $this->createMock(ConfigRepository::class);
        $this->adapter = $this->getMockBuilder(Adapter::class)
            ->setConstructorArgs([
                $this->config,
                $this->createMock(BrandRegistryInterface::class),
                $this->createMock(CurlFactory::class),
                $this->createMock(LogRepository::class),
                new NullApiTranslator(),
            ])
            ->onlyMethods(['execute'])
            ->getMock();

        $this->log = $this->createMock(LogRepository::class);
        $this->ratesProvider = $this->createMock(CurrencyRatesProviderInterface::class);
        $this->cache = $this->createMock(CacheInterface::class);
        $this->cache->method('load')->willReturn(false);

        // No merchant cap unless a test stubs one — the mock's own null return
        // would otherwise make every clamp assertion below vacuous.
        $this->settings = $this->getMockBuilder(SettingsProvider::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->calculator = new SurchargeCalculator(
            $this->config,
            $this->adapter,
            $this->log,
            $this->ratesProvider,
            $this->cache,
            new Json(),
            new SurchargeCapProvider($this->settings, $this->ratesProvider)
        );
    }

    /** A calculator wired to a fresh instance — simulates a new PHP request: no per-request memo, only whatever $cache serves. */
    private function freshRequestCalculator(CacheInterface $cache): SurchargeCalculator
    {
        return new SurchargeCalculator(
            $this->config,
            $this->adapter,
            $this->log,
            $this->ratesProvider,
            $cache,
            new Json(),
            new SurchargeCapProvider($this->settings, $this->ratesProvider)
        );
    }

    /** The merchant's fixed-surcharge cap, as the merchant record carries it; null for no cap. */
    private function stubMerchantCap(?float $amount, string $currency = 'EUR'): void
    {
        $this->settings->method('getSurchargeLimit')->willReturn(
            $amount === null ? null : ['amount' => $amount, 'currency' => $currency]
        );
    }

    /**
     * TWO-25503: a missing FX rate makes the METHOD unofferable, so callers
     * need to ask before converting anything. Given/When/Then per case.
     *
     * @dataProvider resolvabilityProvider
     */
    public function testIsSurchargeResolvable(
        string $type,
        string $fixedCurrency,
        string $orderCurrency,
        ?float $rate,
        float $fixed,
        ?float $limit,
        bool $expected,
        string $case
    ): void {
        $this->config->method('getSurchargeType')->willReturn($type);
        $this->config->method('getSurchargeFixedCurrency')->willReturn($fixedCurrency);
        $this->config->method('getAllBuyerTerms')->willReturn([14, 30]);
        $this->config->method('getSurchargeConfig')->willReturn([
            'percentage' => 2.0,
            'fixed' => $fixed,
            'limit' => $limit,
        ]);
        $this->ratesProvider->method('getRate')->willReturn($rate);

        $this->assertSame(
            $expected,
            $this->calculator->isSurchargeResolvable($orderCurrency, 1),
            $case
        );
    }

    public function resolvabilityProvider(): array
    {
        return [
            [SurchargeType::NONE, 'EUR', 'NOK', null, 10.0, 50.0, true, 'no surcharge configured needs no rate'],
            [SurchargeType::FIXED, 'EUR', 'EUR', null, 10.0, null, true, 'same currency needs no rate'],
            [SurchargeType::FIXED, '', 'NOK', null, 10.0, null, true, 'no fixed currency stored needs no rate'],
            [SurchargeType::FIXED, 'EUR', 'NOK', 11.5, 10.0, null, true, 'a fixed fee with a rate resolves'],
            [SurchargeType::FIXED, 'EUR', 'NOK', null, 10.0, null, false, 'a fixed fee with no rate does not'],
            [SurchargeType::FIXED, 'EUR', 'NOK', null, 0.0, null, true, 'a zero fixed fee is never converted'],
            [SurchargeType::PERCENTAGE, 'EUR', 'NOK', null, 0.0, null, true, 'an uncapped percentage is never converted'],
            [SurchargeType::PERCENTAGE, 'EUR', 'NOK', null, 0.0, 50.0, false, 'a capped percentage with no rate does not'],
            [SurchargeType::PERCENTAGE, 'EUR', 'NOK', 11.5, 0.0, 50.0, true, 'a capped percentage with a rate resolves'],
            [SurchargeType::FIXED_AND_PERCENTAGE, 'EUR', 'NOK', null, 0.0, 50.0, false, 'a combined cap with no rate does not'],
        ];
    }

    private function stubCommonConfig(string $type, bool $differential = false): void
    {
        $this->config->method('getSurchargeType')->willReturn($type);
        $this->config->method('isSurchargeDifferential')->willReturn($differential);
        $this->config->method('getPaymentTermsType')->willReturn('standard');
        $this->config->method('getSurchargeLineDescription')->willReturn('Payment terms fee');
        $this->config->method('getCustomSurchargeTaxRate')->willReturn(0.0);
    }

    private function stubSurchargeConfig(float $percentage = 0, float $fixed = 0, ?float $limit = null): void
    {
        $this->config->method('getSurchargeConfig')->willReturn([
            'percentage' => $percentage,
            'fixed' => $fixed,
            'limit' => $limit,
        ]);
    }

    private function stubFixedCurrency(string $currency = 'NOK'): void
    {
        $this->config->method('getSurchargeFixedCurrency')->willReturn($currency);
    }

    private function stubRounding(string $basis, float $step): void
    {
        $this->config->method('getSurchargeRoundingBasis')->willReturn($basis);
        $this->config->method('getSurchargeRoundingStep')->willReturn($step);
    }

    private function stubFxRate(string $from, float $multiplier): void
    {
        $this->ratesProvider->method('getRate')
            ->with($from, $this->anything())
            ->willReturn($multiplier);
    }

    // ── Short-circuits (no API call) ─────────────────────────────────

    public function testReturnsZeroWhenSurchargeTypeIsNone(): void
    {
        $this->config->method('getSurchargeType')->willReturn(SurchargeType::NONE);
        $this->adapter->expects($this->never())->method('execute');

        $result = $this->calculator->calculate(1000.0, 30, 'NO', 'NOK');

        $this->assertEquals(0.0, $result['amount']);
        $this->assertEquals('', $result['description']);
    }

    public function testDifferentialModeDefaultTermDelegatesToApi(): void
    {
        $this->stubCommonConfig(SurchargeType::PERCENTAGE, true);
        $this->config->method('getDefaultPaymentTerm')->willReturn(30);
        $this->stubSurchargeConfig(50);

        // Differential math is the API's job; the plugin sends the request
        // and trusts the response (which will be 0 at the default term).
        $this->adapter->expects($this->once())
            ->method('execute')
            ->willReturn(['buyer_fee_share' => 0.0]);

        $result = $this->calculator->calculate(1000.0, 30, 'NO', 'NOK');

        $this->assertEquals(0.0, $result['amount']);
    }

    /**
     * Given a quote in a non-default store; When the fee is priced; Then the
     * pricing call is made under that store's key, not the default scope's.
     */
    public function testThePricingCallCarriesTheStoreTheFeeWasResolvedFor(): void
    {
        $this->stubCommonConfig(SurchargeType::PERCENTAGE);
        $this->stubSurchargeConfig(50);

        $this->adapter->expects($this->once())
            ->method('execute')
            ->with('/v1/pricing/order/fee', $this->anything(), 'POST', 7)
            ->willReturn(['buyer_fee_share' => 17.50]);

        $this->calculator->calculate(1000.0, 60, 'NO', 'NOK', 7);
    }

    // ── API response is authoritative ────────────────────────────────

    public function testReturnsAuthoritativeFeeFromApi(): void
    {
        $this->stubCommonConfig(SurchargeType::PERCENTAGE);
        $this->stubSurchargeConfig(50);

        $this->adapter->method('execute')->willReturn(['buyer_fee_share' => 17.50]);

        $result = $this->calculator->calculate(1000.0, 60, 'NO', 'NOK');

        $this->assertEquals(17.50, $result['amount']);
    }

    public function testThrowsWhenApiOmitsBuyerFeeShare(): void
    {
        $this->stubCommonConfig(SurchargeType::PERCENTAGE);
        $this->stubSurchargeConfig(50);

        $this->adapter->method('execute')->willReturn([]);

        $this->expectException(\Magento\Framework\Exception\LocalizedException::class);
        $this->expectExceptionMessage('Pricing API response missing required field: buyer_fee_share');

        $this->calculator->calculate(1000.0, 60, 'NO', 'NOK');
    }

    public function testThrowsWithUpstreamErrorWhenApiReturnsNon2xx(): void
    {
        $this->stubCommonConfig(SurchargeType::PERCENTAGE);
        $this->stubSurchargeConfig(50);

        // Adapter merges the upstream error body with http_status. The
        // calculator must surface that — not mask it as a schema bug.
        $this->adapter->method('execute')->willReturn([
            'http_status' => 401,
            'error_code' => 'AUTHENTICATION_INVALID',
            'error_message' => 'X-API-Key is incorrect or has expired',
            'error_trace_id' => 'abc123',
        ]);

        $this->expectException(\Magento\Framework\Exception\LocalizedException::class);
        // User-facing message intentionally omits HTTP status / upstream reason
        // (those are logged for ops). Trace ID is included for support lookup.
        $this->expectExceptionMessage('Two payment is temporarily unavailable. Please try another payment method or contact support (ref: abc123).');

        $this->calculator->calculate(1000.0, 60, 'NO', 'NOK');
    }

    public function testUpstreamErrorWithoutTraceIdOmitsTraceSegment(): void
    {
        $this->stubCommonConfig(SurchargeType::PERCENTAGE);
        $this->stubSurchargeConfig(50);

        $this->adapter->method('execute')->willReturn([
            'error_code' => 400,
            'error_message' => 'Transport error: timeout',
        ]);

        $this->expectException(\Magento\Framework\Exception\LocalizedException::class);
        $this->expectExceptionMessage('Two payment is temporarily unavailable. Please try another payment method or contact support.');

        $this->calculator->calculate(1000.0, 60, 'NO', 'NOK');
    }

    public function testHttpStatus2xxDoesNotTriggerErrorPathEvenIfFieldPresent(): void
    {
        // Regression: previously the guard fired on any `http_status` key,
        // including 200. Adapters that always include status in their return
        // (observability practice) must not break the success path.
        $this->stubCommonConfig(SurchargeType::PERCENTAGE);
        $this->stubSurchargeConfig(50);

        $this->adapter->method('execute')->willReturn([
            'http_status'     => 200,
            'buyer_fee_share' => 17.50,
        ]);

        $result = $this->calculator->calculate(1000.0, 60, 'NO', 'NOK');
        $this->assertEquals(17.50, $result['amount']);
    }

    // ── Payload mapping ──────────────────────────────────────────────

    public function testPayloadIncludesCurrencyAndOrderTerms(): void
    {
        $this->stubCommonConfig(SurchargeType::PERCENTAGE);
        $this->stubSurchargeConfig(50);

        $this->adapter->expects($this->once())
            ->method('execute')
            ->with(
                '/v1/pricing/order/fee',
                $this->callback(function ($payload) {
                    return $payload['currency'] === 'NOK'
                        && $payload['gross_amount'] === 1000.0
                        && $payload['buyer_country_code'] === 'NO'
                        && $payload['order_terms']['type'] === 'NET_TERMS'
                        && $payload['order_terms']['duration_days'] === 60;
                })
            )
            ->willReturn(['buyer_fee_share' => 0]);

        $this->calculator->calculate(1000.0, 60, 'NO', 'NOK');
    }

    public function testPayloadBuyerFeeShareForPercentage(): void
    {
        $this->stubCommonConfig(SurchargeType::PERCENTAGE);
        $this->stubSurchargeConfig(75);

        $this->adapter->expects($this->once())
            ->method('execute')
            ->with(
                '/v1/pricing/order/fee',
                $this->callback(function ($payload) {
                    $share = $payload['buyer_fee_share'];
                    return $share['surcharge_basis'] === 'buyer_pays'
                        && $share['percentage'] === 75.0
                        && !array_key_exists('surcharge', $share)
                        && !array_key_exists('cap', $share)
                        && !array_key_exists('reference_terms', $share);
                })
            )
            ->willReturn(['buyer_fee_share' => 0]);

        $this->calculator->calculate(1000.0, 60, 'NO', 'NOK');
    }

    public function testPayloadBuyerFeeShareForFixed(): void
    {
        $this->stubCommonConfig(SurchargeType::FIXED);
        $this->stubSurchargeConfig(0, 15);
        $this->stubFixedCurrency('NOK');

        $this->adapter->expects($this->once())
            ->method('execute')
            ->with(
                '/v1/pricing/order/fee',
                $this->callback(function ($payload) {
                    $share = $payload['buyer_fee_share'];
                    return $share['percentage'] === 0.0 && $share['surcharge'] === 15.0;
                })
            )
            ->willReturn(['buyer_fee_share' => 0]);

        $this->calculator->calculate(1000.0, 60, 'NO', 'NOK');
    }

    public function testPayloadBuyerFeeShareForFixedAndPercentage(): void
    {
        $this->stubCommonConfig(SurchargeType::FIXED_AND_PERCENTAGE);
        $this->stubSurchargeConfig(50, 5);
        $this->stubFixedCurrency('NOK');

        $this->adapter->expects($this->once())
            ->method('execute')
            ->with(
                '/v1/pricing/order/fee',
                $this->callback(function ($payload) {
                    $share = $payload['buyer_fee_share'];
                    return $share['percentage'] === 50.0 && $share['surcharge'] === 5.0;
                })
            )
            ->willReturn(['buyer_fee_share' => 0]);

        $this->calculator->calculate(1000.0, 60, 'NO', 'NOK');
    }

    public function testPayloadIncludesCapWhenLimitSet(): void
    {
        $this->stubCommonConfig(SurchargeType::FIXED_AND_PERCENTAGE);
        $this->stubSurchargeConfig(50, 5, 30);
        $this->stubFixedCurrency('NOK');

        $this->adapter->expects($this->once())
            ->method('execute')
            ->with(
                '/v1/pricing/order/fee',
                $this->callback(function ($payload) {
                    return $payload['buyer_fee_share']['cap'] === 30.0;
                })
            )
            ->willReturn(['buyer_fee_share' => 0]);

        $this->calculator->calculate(1000.0, 60, 'NO', 'NOK');
    }

    public function testPayloadOmitsCapWhenLimitNull(): void
    {
        $this->stubCommonConfig(SurchargeType::PERCENTAGE);
        $this->stubSurchargeConfig(50, 0, null);

        $this->adapter->expects($this->once())
            ->method('execute')
            ->with(
                '/v1/pricing/order/fee',
                $this->callback(function ($payload) {
                    return !array_key_exists('cap', $payload['buyer_fee_share']);
                })
            )
            ->willReturn(['buyer_fee_share' => 0]);

        $this->calculator->calculate(1000.0, 60, 'NO', 'NOK');
    }

    public function testPayloadOmitsCapInFixedOnlyModeEvenWhenLimitSet(): void
    {
        // A fixed-only fee is constant and has nothing to clamp; the admin grid
        // never exposes the Limit field for this type. A stored limit (e.g. left
        // over from a previous percentage configuration) must not leak into the
        // request and clamp the fixed fee.
        $this->stubCommonConfig(SurchargeType::FIXED);
        $this->stubSurchargeConfig(0, 10, 25.0);
        $this->stubFixedCurrency('NOK');

        $this->adapter->expects($this->once())
            ->method('execute')
            ->with(
                '/v1/pricing/order/fee',
                $this->callback(function ($payload) {
                    return !array_key_exists('cap', $payload['buyer_fee_share']);
                })
            )
            ->willReturn(['buyer_fee_share' => 0]);

        $this->calculator->calculate(1000.0, 30, 'NO', 'NOK');
    }

    public function testPayloadIncludesCapInPercentageOnlyMode(): void
    {
        // The Limit field IS exposed for the percentage type, so a configured
        // limit must still be sent as the cap when no fixed component is present.
        $this->stubCommonConfig(SurchargeType::PERCENTAGE);
        $this->stubSurchargeConfig(50, 0, 30);
        $this->stubFixedCurrency('NOK');

        $this->adapter->expects($this->once())
            ->method('execute')
            ->with(
                '/v1/pricing/order/fee',
                $this->callback(function ($payload) {
                    return $payload['buyer_fee_share']['cap'] === 30.0
                        && !array_key_exists('surcharge', $payload['buyer_fee_share']);
                })
            )
            ->willReturn(['buyer_fee_share' => 0]);

        $this->calculator->calculate(1000.0, 60, 'NO', 'NOK');
    }

    // ── Rounding ─────────────────────────────────────────────────────

    public function testPayloadIncludesRoundingForPercentage(): void
    {
        $this->stubCommonConfig(SurchargeType::PERCENTAGE);
        $this->stubSurchargeConfig(50);
        $this->stubRounding(RoundingBasis::UP, 1.0);

        $this->adapter->expects($this->once())
            ->method('execute')
            ->with(
                '/v1/pricing/order/fee',
                $this->callback(function ($payload) {
                    $share = $payload['buyer_fee_share'];
                    return $share['rounding'] === ['step' => 1.0, 'basis' => 'UP']
                        && $share['percentage'] === 50.0;
                })
            )
            ->willReturn(['buyer_fee_share' => 0]);

        $this->calculator->calculate(1000.0, 60, 'NO', 'NOK');
    }

    public function testPayloadIncludesRoundingForFixedAndPercentage(): void
    {
        $this->stubCommonConfig(SurchargeType::FIXED_AND_PERCENTAGE);
        $this->stubSurchargeConfig(50, 5);
        $this->stubFixedCurrency('NOK');
        $this->stubRounding(RoundingBasis::STANDARD, 0.5);

        $this->adapter->expects($this->once())
            ->method('execute')
            ->with(
                '/v1/pricing/order/fee',
                $this->callback(function ($payload) {
                    $share = $payload['buyer_fee_share'];
                    // Rounding is added alongside the other keys, not in place of them.
                    return $share['rounding'] === ['step' => 0.5, 'basis' => 'STANDARD']
                        && $share['surcharge'] === 5.0
                        && $share['percentage'] === 50.0;
                })
            )
            ->willReturn(['buyer_fee_share' => 0]);

        $this->calculator->calculate(1000.0, 60, 'NO', 'NOK');
    }

    public function testRoundingBasisDownMapsToApiEnum(): void
    {
        $this->stubCommonConfig(SurchargeType::PERCENTAGE);
        $this->stubSurchargeConfig(50);
        $this->stubRounding(RoundingBasis::DOWN, 10.0);

        $this->adapter->expects($this->once())
            ->method('execute')
            ->with(
                '/v1/pricing/order/fee',
                $this->callback(function ($payload) {
                    // Full structure: basis maps to DOWN and the step passes through intact.
                    return $payload['buyer_fee_share']['rounding'] === ['step' => 10.0, 'basis' => 'DOWN'];
                })
            )
            ->willReturn(['buyer_fee_share' => 0]);

        $this->calculator->calculate(1000.0, 60, 'NO', 'NOK');
    }

    public function testPayloadOmitsRoundingWhenBasisNone(): void
    {
        $this->stubCommonConfig(SurchargeType::PERCENTAGE);
        $this->stubSurchargeConfig(50);
        // A configured step is irrelevant when the basis is "none".
        $this->stubRounding(RoundingBasis::NONE, 1.0);

        $this->adapter->expects($this->once())
            ->method('execute')
            ->with(
                '/v1/pricing/order/fee',
                $this->callback(function ($payload) {
                    return !array_key_exists('rounding', $payload['buyer_fee_share']);
                })
            )
            ->willReturn(['buyer_fee_share' => 0]);

        $this->calculator->calculate(1000.0, 60, 'NO', 'NOK');
    }

    /**
     * @dataProvider nonPositiveStepProvider
     */
    public function testPayloadOmitsRoundingWhenStepNotPositive(float $step): void
    {
        // A basis with a non-positive step is incomplete and the API rejects
        // step <= 0, so the block is omitted rather than sent invalid.
        $this->stubCommonConfig(SurchargeType::PERCENTAGE);
        $this->stubSurchargeConfig(50);
        $this->stubRounding(RoundingBasis::STANDARD, $step);

        $this->adapter->expects($this->once())
            ->method('execute')
            ->with(
                '/v1/pricing/order/fee',
                $this->callback(function ($payload) {
                    return !array_key_exists('rounding', $payload['buyer_fee_share']);
                })
            )
            ->willReturn(['buyer_fee_share' => 0]);

        $this->calculator->calculate(1000.0, 60, 'NO', 'NOK');
    }

    /** @return array<string, array{float}> */
    public function nonPositiveStepProvider(): array
    {
        return [
            'zero step' => [0.0],
            'negative step' => [-5.0],
        ];
    }

    public function testPayloadOmitsRoundingInFixedOnlyModeEvenWhenConfigured(): void
    {
        // The admin never exposes rounding for a fixed-only fee (there is nothing
        // to snap). A stored basis/step left over from a previous percentage
        // configuration must not leak into a fixed-only request.
        $this->stubCommonConfig(SurchargeType::FIXED);
        $this->stubSurchargeConfig(0, 10);
        $this->stubFixedCurrency('NOK');
        $this->stubRounding(RoundingBasis::UP, 1.0);

        $this->adapter->expects($this->once())
            ->method('execute')
            ->with(
                '/v1/pricing/order/fee',
                $this->callback(function ($payload) {
                    return !array_key_exists('rounding', $payload['buyer_fee_share']);
                })
            )
            ->willReturn(['buyer_fee_share' => 0]);

        $this->calculator->calculate(1000.0, 30, 'NO', 'NOK');
    }

    // ── Differential mode (reference_terms) ──────────────────────────

    /**
     * @dataProvider referenceTermsProvider
     */
    public function testDifferentialReferenceTerms(
        bool $differential,
        ?int $defaultTerm,
        ?int $expectedReferenceDays,
        string $case
    ): void {
        $this->stubCommonConfig(SurchargeType::PERCENTAGE, $differential);
        $this->config->method('getDefaultPaymentTerm')->willReturn($defaultTerm);
        $this->stubSurchargeConfig(75);

        $this->adapter->expects($this->once())
            ->method('execute')
            ->with(
                '/v1/pricing/order/fee',
                $this->callback(function ($payload) use ($expectedReferenceDays, $case) {
                    $share = $payload['buyer_fee_share'];
                    if ($expectedReferenceDays === null) {
                        $this->assertArrayNotHasKey('reference_terms', $share, $case);
                        return true;
                    }
                    $this->assertSame('NET_TERMS', $share['reference_terms']['type'], $case);
                    $this->assertSame($expectedReferenceDays, $share['reference_terms']['duration_days'], $case);
                    return true;
                })
            )
            ->willReturn(['buyer_fee_share' => 11.25]);

        $result = $this->calculator->calculate(1000.0, 60, 'NO', 'NOK');

        $this->assertEquals(11.25, $result['amount'], $case);
    }

    public static function referenceTermsProvider(): array
    {
        return [
            [true, 30, 30, 'differential prices against the default term'],
            [false, 30, null, 'a non-differential fee carries no reference term'],
            [true, null, null, 'no offered term leaves no reference term to price against'],
        ];
    }

    // ── End of month terms ───────────────────────────────────────────

    public function testEndOfMonthTermsPassedToApi(): void
    {
        $this->config->method('getSurchargeType')->willReturn(SurchargeType::PERCENTAGE);
        $this->config->method('isSurchargeDifferential')->willReturn(true);
        $this->config->method('getDefaultPaymentTerm')->willReturn(30);
        $this->config->method('getPaymentTermsType')->willReturn('end_of_month');
        $this->config->method('getSurchargeLineDescription')->willReturn('Payment terms fee');
        $this->config->method('getCustomSurchargeTaxRate')->willReturn(0.0);
        $this->stubSurchargeConfig(100);

        $this->adapter->expects($this->once())
            ->method('execute')
            ->with(
                '/v1/pricing/order/fee',
                $this->callback(function ($payload) {
                    return $payload['order_terms']['duration_days_calculated_from'] === 'END_OF_MONTH'
                        && $payload['order_terms']['duration_days'] === 60
                        && $payload['buyer_fee_share']['reference_terms']['duration_days_calculated_from']
                            === 'END_OF_MONTH'
                        && $payload['buyer_fee_share']['reference_terms']['duration_days'] === 30;
                })
            )
            ->willReturn(['buyer_fee_share' => 40.0]);

        $result = $this->calculator->calculate(1000.0, 60, 'NO', 'NOK');

        $this->assertEquals(40.0, $result['amount']);
    }

    // ── Tax rate and description ─────────────────────────────────────

    public function testReturnsTaxRateAndDescription(): void
    {
        $this->config->method('getSurchargeType')->willReturn(SurchargeType::FIXED);
        $this->config->method('isSurchargeDifferential')->willReturn(false);
        $this->config->method('getPaymentTermsType')->willReturn('standard');
        $this->config->method('getSurchargeLineDescription')->willReturn('Extended terms fee - %1 days');
        $this->config->method('getCustomSurchargeTaxRate')->willReturn(25.0);
        $this->stubSurchargeConfig(0, 10);
        $this->stubFixedCurrency('NOK');

        $this->adapter->method('execute')->willReturn(['buyer_fee_share' => 10.0]);

        $result = $this->calculator->calculate(1000.0, 30, 'NO', 'NOK');

        $this->assertEquals(10.0, $result['amount']);
        $this->assertEquals(25.0, $result['tax_rate']);
        $this->assertEquals('Extended terms fee - 30 days', $result['description']);
    }

    // ── Currency conversion (merchant config amounts only) ──────────

    public function testFixedFeeConvertedInPayloadWhenOrderCurrencyDiffers(): void
    {
        $this->stubCommonConfig(SurchargeType::FIXED);
        $this->stubSurchargeConfig(0, 10);
        $this->stubFixedCurrency('NOK');
        $this->stubFxRate('NOK', 0.088); // 10 NOK → 0.88 EUR

        $this->adapter->expects($this->once())
            ->method('execute')
            ->with(
                '/v1/pricing/order/fee',
                $this->callback(function ($payload) {
                    return abs($payload['buyer_fee_share']['surcharge'] - 0.88) < 0.0001
                        && $payload['currency'] === 'EUR';
                })
            )
            ->willReturn(['buyer_fee_share' => 0.88]);

        $result = $this->calculator->calculate(1000.0, 30, 'NO', 'EUR');

        $this->assertEquals(0.88, $result['amount']);
    }

    public function testFixedFeeNotConvertedWhenSameCurrency(): void
    {
        $this->stubCommonConfig(SurchargeType::FIXED);
        $this->stubSurchargeConfig(0, 15);
        $this->stubFixedCurrency('NOK');

        $this->ratesProvider->expects($this->never())->method('getRate');

        $this->adapter->expects($this->once())
            ->method('execute')
            ->with(
                '/v1/pricing/order/fee',
                $this->callback(function ($payload) {
                    return $payload['buyer_fee_share']['surcharge'] === 15.0;
                })
            )
            ->willReturn(['buyer_fee_share' => 15.0]);

        $result = $this->calculator->calculate(1000.0, 60, 'NO', 'NOK');

        $this->assertEquals(15.0, $result['amount']);
    }

    public function testCapConvertedInPayloadWhenOrderCurrencyDiffers(): void
    {
        $this->stubCommonConfig(SurchargeType::FIXED_AND_PERCENTAGE);
        $this->stubSurchargeConfig(100, 5, 20);
        $this->stubFixedCurrency('NOK');
        $this->stubFxRate('NOK', 1.1); // 1 NOK = 1.1 SEK

        $this->adapter->expects($this->once())
            ->method('execute')
            ->with(
                '/v1/pricing/order/fee',
                $this->callback(function ($payload) {
                    $share = $payload['buyer_fee_share'];
                    return abs($share['surcharge'] - 5.5) < 0.0001
                        && abs($share['cap'] - 22.0) < 0.0001;
                })
            )
            ->willReturn(['buyer_fee_share' => 22.0]);

        $result = $this->calculator->calculate(5000.0, 90, 'NO', 'SEK');

        $this->assertEquals(22.0, $result['amount']);
    }

    public function testThrowsWhenCurrencyConversionFails(): void
    {
        $this->stubCommonConfig(SurchargeType::FIXED);
        $this->stubSurchargeConfig(0, 10);
        $this->stubFixedCurrency('NOK');

        $this->ratesProvider->method('getRate')
            ->with('NOK', 'GBP')
            ->willReturn(null);

        $this->expectException(\Magento\Framework\Exception\LocalizedException::class);
        $this->expectExceptionMessage('Cannot convert surcharge from NOK to GBP');

        $this->calculator->calculate(1000.0, 30, 'NO', 'GBP');
    }

    public function testFailClosedFxErrorIsLoggedWithCurrencyPairAndTerm(): void
    {
        // The buyer sees a checkout error either way; without this log ops and
        // the merchant see nothing at all and a bad currency pair reads as an
        // unexplained drop-off.
        $this->stubCommonConfig(SurchargeType::FIXED);
        $this->stubSurchargeConfig(0, 10);
        $this->stubFixedCurrency('NOK');
        $this->ratesProvider->method('getRate')->willReturn(null);

        $this->log->expects($this->once())
            ->method('addErrorLog')
            ->with(
                'Surcharge FX conversion failed: no rate available',
                $this->callback(function ($context) {
                    return $context['from_currency'] === 'NOK'
                        && $context['to_currency'] === 'GBP'
                        && $context['selected_term'] === 45;
                })
            );

        $this->expectException(\Magento\Framework\Exception\LocalizedException::class);

        $this->calculator->calculate(1000.0, 45, 'NO', 'GBP');
    }

    // ── A configured limit of 0 is a real cap of zero, sent as `cap => 0.0` ──

    public function testConfiguredCapOfZeroIsSentAsZeroCap(): void
    {
        // A limit of exactly 0 caps the fee at nothing, i.e. charges no
        // surcharge, and `cap => 0.0` is how the API is told that — 0 clamps
        // rather than uncaps. TWO-25269 briefly threw a LocalizedException here
        // on the false premise that a zero cap would relay an uncapped
        // percentage; that guard is gone and must not come back. This test is
        // its headstone.
        $this->stubCommonConfig(SurchargeType::PERCENTAGE);
        $this->stubSurchargeConfig(50, 0, 0.0);
        $this->stubFixedCurrency('NOK');

        $this->log->expects($this->never())->method('addErrorLog');

        $this->adapter->expects($this->once())
            ->method('execute')
            ->with(
                '/v1/pricing/order/fee',
                $this->callback(function ($payload) {
                    $share = $payload['buyer_fee_share'];
                    return array_key_exists('cap', $share)
                        && $share['cap'] === 0.0
                        && $share['percentage'] === 50.0;
                })
            )
            ->willReturn(['buyer_fee_share' => 0.0]);

        $result = $this->calculator->calculate(1000.0, 60, 'NO', 'NOK');

        $this->assertEquals(0.0, $result['amount']);
    }

    public function testZeroCapNeedsNoFxRateEvenAcrossCurrencies(): void
    {
        // Pins convertAmount()'s `$amount === 0.0` early return: a zero limit
        // must reach the API as `cap => 0.0` on a cross-currency order with NO
        // rate available for the pair. Without that early return this mock's
        // getRate() returns null and checkout hard-fails, so a percentage-only
        // merchant with limit 0 and an unrated pair would break. PERCENTAGE
        // (not mixed) so nothing else needs a rate either.
        $this->stubCommonConfig(SurchargeType::PERCENTAGE);
        $this->stubSurchargeConfig(50, 0, 0.0);
        $this->stubFixedCurrency('NOK');
        $this->ratesProvider->method('getRate')->willReturn(null);

        $this->log->expects($this->never())->method('addErrorLog');

        $this->adapter->expects($this->once())
            ->method('execute')
            ->with(
                '/v1/pricing/order/fee',
                $this->callback(function ($payload) {
                    return $payload['buyer_fee_share']['cap'] === 0.0;
                })
            )
            ->willReturn(['buyer_fee_share' => 0.0]);

        $this->calculator->calculate(1000.0, 90, 'NO', 'SEK');
    }

    public function testZeroCapIsSentAlongsideTheConvertedFixedSurcharge(): void
    {
        // Payload-level guard for the mixed type: `cap => 0.0` is sent verbatim
        // and does NOT disturb the FX-converted `surcharge`.
        //
        // API-side (not pinnable from here — the adapter is mocked): the cap
        // clamps the SUM of the fixed passthrough and the percentage
        // contribution, so a limit of 0 zeroes the configured Fixed fee too,
        // not just the percentage part. Easy to misread as "Limit only bounds
        // the %", and reachable straight from the admin grid.
        $this->stubCommonConfig(SurchargeType::FIXED_AND_PERCENTAGE);
        $this->stubSurchargeConfig(50, 5, 0.0);
        $this->stubFixedCurrency('NOK');
        $this->stubFxRate('NOK', 1.1);

        $this->log->expects($this->never())->method('addErrorLog');

        $this->adapter->expects($this->once())
            ->method('execute')
            ->with(
                '/v1/pricing/order/fee',
                $this->callback(function ($payload) {
                    $share = $payload['buyer_fee_share'];
                    return $share['cap'] === 0.0
                        && abs($share['surcharge'] - 5.5) < 0.0001;
                })
            )
            ->willReturn(['buyer_fee_share' => 0.0]);

        $this->calculator->calculate(1000.0, 90, 'NO', 'SEK');
    }

    public function testZeroLimitInFixedOnlyModeSendsNoCap(): void
    {
        // `cap` sits behind a $hasPercentage gate: a stale zero limit left over
        // from a previous surcharge type must not leak into a fixed-only fee,
        // which is constant and has nothing to clamp.
        $this->stubCommonConfig(SurchargeType::FIXED);
        $this->stubSurchargeConfig(0, 10, 0.0);
        $this->stubFixedCurrency('NOK');

        $this->log->expects($this->never())->method('addErrorLog');

        $this->adapter->expects($this->once())
            ->method('execute')
            ->with(
                '/v1/pricing/order/fee',
                $this->callback(function ($payload) {
                    return !array_key_exists('cap', $payload['buyer_fee_share']);
                })
            )
            ->willReturn(['buyer_fee_share' => 10.0]);

        $result = $this->calculator->calculate(1000.0, 60, 'NO', 'NOK');

        $this->assertEquals(10.0, $result['amount']);
    }

    // ── An ABSENT cap is legitimate: uncapped percentage must still charge ──

    public function testAbsentLimitSendsUncappedPercentageSurcharge(): void
    {
        // REGRESSION GUARD. "No cap defined" is a valid, common configuration
        // and is distinct from a cap of 0: an absent limit must send no `cap`
        // key at all so the percentage is applied uncapped, with no error.
        $this->stubCommonConfig(SurchargeType::PERCENTAGE);
        $this->stubSurchargeConfig(50, 0, null);
        $this->stubFixedCurrency('NOK');

        $this->log->expects($this->never())->method('addErrorLog');

        $this->adapter->expects($this->once())
            ->method('execute')
            ->with(
                '/v1/pricing/order/fee',
                $this->callback(function ($payload) {
                    $share = $payload['buyer_fee_share'];
                    return !array_key_exists('cap', $share)
                        && $share['percentage'] === 50.0;
                })
            )
            ->willReturn(['buyer_fee_share' => 25.0]);

        $result = $this->calculator->calculate(1000.0, 60, 'NO', 'NOK');

        $this->assertEquals(25.0, $result['amount']);
    }

    public function testAbsentLimitSendsUncappedFixedAndPercentageSurcharge(): void
    {
        // Same for the mixed type, where a `surcharge` FX conversion also runs
        // — an absent cap must not disturb it.
        $this->stubCommonConfig(SurchargeType::FIXED_AND_PERCENTAGE);
        $this->stubSurchargeConfig(50, 5, null);
        $this->stubFixedCurrency('NOK');
        $this->stubFxRate('NOK', 1.1);

        $this->log->expects($this->never())->method('addErrorLog');

        $this->adapter->expects($this->once())
            ->method('execute')
            ->with(
                '/v1/pricing/order/fee',
                $this->callback(function ($payload) {
                    $share = $payload['buyer_fee_share'];
                    return !array_key_exists('cap', $share)
                        && $share['percentage'] === 50.0
                        && abs($share['surcharge'] - 5.5) < 0.0001;
                })
            )
            ->willReturn(['buyer_fee_share' => 30.0]);

        $result = $this->calculator->calculate(1000.0, 60, 'NO', 'SEK');

        $this->assertEquals(30.0, $result['amount']);
    }

    public function testCapAndSurchargeAreRoundedToTwoDecimalPlacesOnTheWire(): void
    {
        // The API refuses monetary values finer than two decimal places
        // rather than rounding them, so an unrounded FX conversion was
        // rejected upstream and reached the buyer as a generic
        // "temporarily unavailable" error (TWO-25289).
        //
        // 349 * 0.0872 = 30.4328 → 30.43 for both components.
        $this->stubCommonConfig(SurchargeType::FIXED_AND_PERCENTAGE);
        $this->stubSurchargeConfig(50, 349, 349);
        $this->stubFixedCurrency('NOK');
        $this->stubFxRate('NOK', 0.0872);

        $this->log->expects($this->never())->method('addErrorLog');

        $sent = null;
        $this->adapter->expects($this->once())
            ->method('execute')
            ->with(
                '/v1/pricing/order/fee',
                $this->callback(function ($payload) use (&$sent) {
                    $sent = $payload['buyer_fee_share'];

                    return true;
                })
            )
            ->willReturn(['buyer_fee_share' => 30.43]);

        $this->calculator->calculate(1000.0, 60, 'NO', 'SEK');

        $this->assertSame(30.43, $sent['cap']);
        $this->assertSame(30.43, $sent['surcharge']);
    }

    public function testOverPreciseConfigIsRoundedEvenWithNoCurrencyConversion(): void
    {
        // No FX involved (config currency === order currency), but an admin
        // can type more precision than the API accepts, so the
        // no-conversion path needs the same 2dp gate (TWO-25289).
        $this->stubCommonConfig(SurchargeType::PERCENTAGE);
        $this->stubSurchargeConfig(50, 0, 10.999);
        $this->stubFixedCurrency('SEK');

        $this->log->expects($this->never())->method('addErrorLog');

        $sent = null;
        $this->adapter->expects($this->once())
            ->method('execute')
            ->with(
                '/v1/pricing/order/fee',
                $this->callback(function ($payload) use (&$sent) {
                    $sent = $payload['buyer_fee_share']['cap'];

                    return true;
                })
            )
            ->willReturn(['buyer_fee_share' => 11.0]);

        $this->calculator->calculate(1000.0, 60, 'NO', 'SEK');

        $this->assertSame(11.0, $sent);
    }

    public function testASubCentCapRoundsDownToZeroWhichIsAcceptedScope(): void
    {
        // 0.01 NOK under a 0.0001 rate is 0.000001, which rounds to 0.00 and
        // therefore suppresses the fee entirely. Deliberate, and pinned so it
        // is a decision rather than a surprise: sub-cent caps, away-from-zero
        // rounding and zero-decimal currencies are all explicitly out of
        // scope (TWO-25289). Rounding half-up beats the alternative of
        // shipping a >2dp value the API rejects outright.
        $this->stubCommonConfig(SurchargeType::PERCENTAGE);
        $this->stubSurchargeConfig(50, 0, 0.01);
        $this->stubFixedCurrency('NOK');
        $this->stubFxRate('NOK', 0.0001);

        $this->log->expects($this->never())->method('addErrorLog');

        $sent = null;
        $this->adapter->expects($this->once())
            ->method('execute')
            ->with(
                '/v1/pricing/order/fee',
                $this->callback(function ($payload) use (&$sent) {
                    $sent = $payload['buyer_fee_share']['cap'];

                    return true;
                })
            )
            ->willReturn(['buyer_fee_share' => 0.0]);

        $this->calculator->calculate(1000.0, 60, 'NO', 'SEK');

        $this->assertSame(0.0, $sent);
    }

    public function testConversionForwardsStoreScopeToRateLookup(): void
    {
        // FX rates are fetched with the store-scoped API key (TWO-25103):
        // dropping the store id would resolve the default scope's key and
        // break multi-store installs with per-store keys.
        $this->stubCommonConfig(SurchargeType::FIXED);
        $this->stubSurchargeConfig(0, 10);
        $this->stubFixedCurrency('NOK');
        $this->ratesProvider->expects($this->atLeastOnce())->method('getRate')
            ->with('NOK', 'SEK', 7)
            ->willReturn(1.1);
        $this->adapter->method('execute')->willReturn(['buyer_fee_share' => 11.0]);

        $this->calculator->calculate(1000.0, 30, 'NO', 'SEK', 7);
    }

    public function testNoConversionWhenFixedCurrencyEmpty(): void
    {
        $this->stubCommonConfig(SurchargeType::FIXED);
        $this->stubSurchargeConfig(0, 10);
        $this->stubFixedCurrency('');

        $this->ratesProvider->expects($this->never())->method('getRate');

        $this->adapter->expects($this->once())
            ->method('execute')
            ->with(
                '/v1/pricing/order/fee',
                $this->callback(function ($payload) {
                    return $payload['buyer_fee_share']['surcharge'] === 10.0;
                })
            )
            ->willReturn(['buyer_fee_share' => 10.0]);

        $result = $this->calculator->calculate(1000.0, 30, 'NO', 'EUR');

        $this->assertEquals(10.0, $result['amount']);
    }

    // ── Fee cache ────────────────────────────────────────────────────

    public function testFeeCacheAvoidsRedundantApiCallsForSameInputs(): void
    {
        $this->stubCommonConfig(SurchargeType::PERCENTAGE);
        $this->stubSurchargeConfig(50);

        $this->adapter->expects($this->once())
            ->method('execute')
            ->willReturn(['buyer_fee_share' => 17.50]);

        $this->calculator->calculate(1000.0, 60, 'NO', 'NOK');
        $second = $this->calculator->calculate(1000.0, 60, 'NO', 'NOK');

        $this->assertEquals(17.50, $second['amount']);
    }

    // ── Cross-request cache (scoped to cart state) ───────────────────

    public function testCrossRequestCacheServedOnUnchangedInputsWithoutHittingTheApi(): void
    {
        // Repeated interactions within the same cart state (opening/
        // closing the chip UI, a re-render across separate requests) must
        // reuse the cached quote rather than re-calling the pricing API.
        $this->stubCommonConfig(SurchargeType::PERCENTAGE);
        $this->stubSurchargeConfig(50);
        $this->adapter->expects($this->once())->method('execute')->willReturn(['buyer_fee_share' => 17.50]);
        $store = [];
        $this->cache = $this->createMock(CacheInterface::class);
        $this->cache->method('load')->willReturnCallback(function ($key) use (&$store) { return $store[$key] ?? false; });
        $this->cache->method('save')->willReturnCallback(function ($data, $key) use (&$store) {
            $store[$key] = $data;
            return true;
        });
        $this->calculator = $this->freshRequestCalculator($this->cache);

        $first = $this->calculator->calculate(1000.0, 60, 'NO', 'NOK');
        // A fresh calculator instance per call simulates a new PHP request:
        // no per-request memo survives, only the persisted cache entry.
        $second = $this->freshRequestCalculator($this->cache)->calculate(1000.0, 60, 'NO', 'NOK');

        $this->assertEquals(17.50, $first['amount']);
        $this->assertEquals(17.50, $second['amount']);
    }

    /**
     * @dataProvider cartStateChangeProvider
     * Mutation-provable: a cache key omitting the varied input would wrongly
     * hit here, and the "exactly twice" expectation below would fail.
     */
    public function testCrossRequestCacheMissesWhenCartStateChanges(
        float $grossAmountA,
        int $termA,
        string $countryA,
        string $currencyA,
        float $grossAmountB,
        int $termB,
        string $countryB,
        string $currencyB,
        string $case
    ): void {
        $this->stubCommonConfig(SurchargeType::PERCENTAGE);
        $this->stubSurchargeConfig(50);
        $calls = 0;
        $this->adapter->method('execute')->willReturnCallback(function () use (&$calls) {
            $calls++;
            return ['buyer_fee_share' => 17.50];
        });
        $store = [];
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('load')->willReturnCallback(function ($key) use (&$store) { return $store[$key] ?? false; });
        $cache->method('save')->willReturnCallback(function ($data, $key) use (&$store) {
            $store[$key] = $data;
            return true;
        });

        $this->freshRequestCalculator($cache)->calculate($grossAmountA, $termA, $countryA, $currencyA);
        $this->freshRequestCalculator($cache)->calculate($grossAmountB, $termB, $countryB, $currencyB);

        $this->assertSame(2, $calls, "a $case must miss the cache and re-quote");
    }

    public function cartStateChangeProvider(): array
    {
        return [
            [1000.0, 60, 'NO', 'NOK', 2000.0, 60, 'NO', 'NOK', 'cart total changed'],
            [1000.0, 60, 'NO', 'NOK', 1000.0, 60, 'NO', 'SEK', 'currency changed'],
            [1000.0, 60, 'NO', 'NOK', 1000.0, 60, 'SE', 'NOK', 'buyer country changed'],
            [1000.0, 60, 'NO', 'NOK', 1000.0, 90, 'NO', 'NOK', 'term changed'],
        ];
    }

    /**
     * Every surcharge quote carries one ceiling, whichever path asked: the
     * availability gate must not refuse a fee the charging path would price.
     */
    public function testEverySurchargeQuoteCarriesThePricingCeiling(): void
    {
        $this->stubCommonConfig(SurchargeType::PERCENTAGE);
        $this->stubSurchargeConfig(2.0);
        $captured = null;
        $this->adapter->method('execute')->willReturnCallback(
            function (...$args) use (&$captured): array {
                $captured = $args[6] ?? null;
                return ['buyer_fee_share' => 20.0, 'currency' => 'NOK'];
            }
        );

        $this->calculator->calculate(1000.0, 30, 'NO', 'NOK', 1);

        // Read rather than restated, so the bound holds if either number moves.
        $adapterDefault = (new \ReflectionClass(Adapter::class))
            ->getConstant('DEFAULT_TIMEOUT_SECONDS');
        $this->assertNotNull($captured, 'the pricing call is bounded, not left to the adapter default');
        $this->assertGreaterThan(0, $captured, 'a timeout of zero would never time out');
        $this->assertLessThan($adapterDefault, $captured, 'the ceiling is tighter than the default');
    }

    public function testCrossRequestCacheNotWrittenOnApiFailureSoNextRequestRetries(): void
    {
        // A failed quote must stay request-scoped: persisting it would
        // mask a recoverable API blip as "no fee" for the whole TTL.
        $this->stubCommonConfig(SurchargeType::PERCENTAGE);
        $this->stubSurchargeConfig(50);
        $this->adapter->method('execute')->willReturn(['error_code' => 503, 'http_status' => 503]);
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('load')->willReturn(false);
        $cache->expects($this->never())->method('save');

        try {
            $this->freshRequestCalculator($cache)->calculate(1000.0, 60, 'NO', 'NOK');
            $this->fail('expected a LocalizedException');
        } catch (\Magento\Framework\Exception\LocalizedException $e) {
            // expected
        }
    }

    // ── The merchant's fixed-surcharge cap ───────────────────────────

    /**
     * The cap bounds the fee that is CHARGED, not merely the value that is
     * stored.
     *
     * Every row is a stored configuration the admin form would have refused, or
     * would have accepted under a cap that has since changed. By the time a fee
     * is priced the route that stored it leaves no trace — a config import and a
     * direct config-table write are the same stored value here — so the rows
     * differ by the numbers that reach the buyer, not by a write mechanism.
     *
     * The fake pricing endpoint bills the surcharge it is sent, which for a
     * fixed-only fee is the whole fee, so the asserted figure is the charge.
     *
     * @dataProvider cappedChargeCases
     */
    public function testTheChargedSurchargeNeverExceedsTheMerchantCap(
        float $storedFixed,
        ?float $capAmount,
        string $orderCurrency,
        ?float $rate,
        float $expectedCharge,
        string $case
    ): void {
        $this->stubCommonConfig(SurchargeType::FIXED);
        $this->stubSurchargeConfig(0, $storedFixed);
        $this->stubFixedCurrency('EUR');
        $this->stubMerchantCap($capAmount);
        $this->ratesProvider->method('getRate')->willReturn($rate);

        $sent = null;
        $this->adapter->method('execute')->willReturnCallback(
            function ($path, $payload) use (&$sent) {
                $sent = $payload['buyer_fee_share']['surcharge'];
                return ['buyer_fee_share' => $sent];
            }
        );

        $charged = $this->calculator->calculate(1000.0, 30, 'NO', $orderCurrency)['amount'];

        $this->assertSame($expectedCharge, $sent, $case . ' — asked of the pricing API');
        $this->assertSame($expectedCharge, $charged, $case . ' — charged to the buyer');
    }

    /**
     * @return array<int, array<int, mixed>>
     */
    public static function cappedChargeCases(): array
    {
        return [
            [10.0, 25.0, 'EUR', null, 10.0, 'an in-cap amount is charged unchanged'],
            [25.0, 25.0, 'EUR', null, 25.0, 'an amount exactly at the cap is charged unchanged'],
            [999.0, 25.0, 'EUR', null, 25.0, 'an amount above the cap is charged at the cap, whatever wrote it'],
            [30.0, 25.0, 'EUR', null, 25.0, 'an amount valid when saved is charged at a cap since lowered'],
            [999.0, null, 'EUR', null, 999.0, 'no merchant cap leaves the configured amount alone'],
            [999.0, 25.0, 'SEK', 10.0, 250.0, 'the cap bounds the fee in the order currency, not the stored one'],
            [10.0, 25.0, 'SEK', 10.0, 100.0, 'an in-cap amount converts without being clamped'],
        ];
    }

    /**
     * A cap no rate expresses in the order currency bounds nothing. The fee
     * itself is already in the order currency here, so the cap is the only
     * conversion failing — and the fee must still not be priced.
     */
    public function testAnUnconvertibleCapRefusesToPriceTheFee(): void
    {
        $this->stubCommonConfig(SurchargeType::FIXED);
        $this->stubSurchargeConfig(0, 999);
        $this->stubFixedCurrency('SEK');
        $this->stubMerchantCap(25.0, 'EUR');
        $this->ratesProvider->method('getRate')->willReturn(null);

        $this->adapter->expects($this->never())->method('execute');

        $this->expectException(\Magento\Framework\Exception\LocalizedException::class);
        $this->expectExceptionMessage('Cannot apply the surcharge limit in SEK');

        $this->calculator->calculate(1000.0, 30, 'NO', 'SEK');
    }

    /**
     * The buyer meets an unofferable payment method rather than the checkout
     * error the refusal above would otherwise become.
     */
    public function testAnUnconvertibleCapWithholdsTheMethod(): void
    {
        $this->config->method('getSurchargeType')->willReturn(SurchargeType::FIXED);
        $this->config->method('getSurchargeFixedCurrency')->willReturn('SEK');
        $this->config->method('getAllBuyerTerms')->willReturn([30]);
        $this->stubSurchargeConfig(0, 999);
        $this->stubMerchantCap(25.0, 'EUR');
        $this->ratesProvider->method('getRate')->willReturn(null);

        $this->assertFalse(
            $this->calculator->isSurchargeResolvable('SEK', 1),
            'a cap with no rate into the order currency makes the surcharge unresolvable'
        );
    }

    /**
     * An unconvertible cap is only a problem for a fee there is something to
     * bound. Withholding the method over a cap on a fee of nothing would take
     * the tile off a working checkout.
     */
    public function testAnUnconvertibleCapOverNoFeeIsHarmless(): void
    {
        $this->config->method('getSurchargeType')->willReturn(SurchargeType::FIXED);
        $this->config->method('getSurchargeFixedCurrency')->willReturn('SEK');
        $this->config->method('getAllBuyerTerms')->willReturn([30]);
        $this->config->method('isSurchargeDifferential')->willReturn(false);
        $this->config->method('getPaymentTermsType')->willReturn('standard');
        $this->config->method('getSurchargeLineDescription')->willReturn('Payment terms fee');
        $this->config->method('getCustomSurchargeTaxRate')->willReturn(0.0);
        $this->stubSurchargeConfig(0, 0.0);
        $this->stubMerchantCap(25.0, 'EUR');
        $this->ratesProvider->method('getRate')->willReturn(null);

        $this->assertTrue(
            $this->calculator->isSurchargeResolvable('SEK', 1),
            'no term carries a fixed amount, so the method stays on offer'
        );

        $this->adapter->method('execute')->willReturn(['buyer_fee_share' => 0.0]);
        $this->assertSame(
            0.0,
            $this->calculator->calculate(1000.0, 30, 'NO', 'SEK')['amount'],
            'and the fee still prices rather than refusing'
        );
    }

    /**
     * A clamp the merchant cannot see is a second defect, not a fix: the admin
     * grid still shows the configured amount.
     */
    public function testAClampedSurchargeIsReported(): void
    {
        $this->stubCommonConfig(SurchargeType::FIXED);
        $this->stubSurchargeConfig(0, 999);
        $this->stubFixedCurrency('EUR');
        $this->stubMerchantCap(25.0);
        $this->adapter->method('execute')->willReturn(['buyer_fee_share' => 25.0]);

        $reported = [];
        $this->log->method('addErrorLog')->willReturnCallback(
            function ($type, $data) use (&$reported) {
                $reported[$type] = $data;
            }
        );

        $this->calculator->calculate(1000.0, 30, 'NO', 'EUR');

        $this->assertArrayHasKey(
            'Surcharge above the merchant cap was reduced to the cap',
            $reported,
            'the reduction must be reported'
        );
        $this->assertSame(
            ['configured_surcharge' => 999.0, 'merchant_cap' => 25, 'order_currency' => 'EUR', 'selected_term' => 30, 'store_id' => null],
            $reported['Surcharge above the merchant cap was reduced to the cap'],
            'the report names the configured amount and the cap that displaced it'
        );
    }
}
