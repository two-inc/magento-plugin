<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Service\Order;

use Magento\Framework\HTTP\Client\Curl;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\BrandRegistryInterface;
use Two\Gateway\Api\Config\RepositoryInterface as ConfigRepository;
use Two\Gateway\Api\CurrencyRatesProviderInterface;
use Two\Gateway\Api\Log\RepositoryInterface as LogRepository;
use Two\Gateway\Model\Config\Source\SurchargeType;
use Two\Gateway\Service\Api\Adapter;
use Two\Gateway\Service\Order\SurchargeCalculator;

class SurchargeCalculatorTest extends TestCase
{
    /** @var ConfigRepository|\PHPUnit\Framework\MockObject\MockObject */
    private $config;

    /** @var Adapter|\PHPUnit\Framework\MockObject\MockObject */
    private $adapter;

    /** @var CurrencyRatesProviderInterface|\PHPUnit\Framework\MockObject\MockObject */
    private $ratesProvider;

    /** @var SurchargeCalculator */
    private $calculator;

    protected function setUp(): void
    {
        $this->config = $this->createMock(ConfigRepository::class);
        $this->adapter = $this->getMockBuilder(Adapter::class)
            ->setConstructorArgs([
                $this->config,
                $this->createMock(BrandRegistryInterface::class),
                $this->createMock(Curl::class),
                $this->createMock(LogRepository::class),
            ])
            ->onlyMethods(['execute'])
            ->getMock();

        $log = $this->createMock(LogRepository::class);
        $this->ratesProvider = $this->createMock(CurrencyRatesProviderInterface::class);

        $this->calculator = new SurchargeCalculator($this->config, $this->adapter, $log, $this->ratesProvider);
    }

    private function stubCommonConfig(string $type, bool $differential = false): void
    {
        $this->config->method('getSurchargeType')->willReturn($type);
        $this->config->method('isSurchargeDifferential')->willReturn($differential);
        $this->config->method('getPaymentTermsType')->willReturn('standard');
        $this->config->method('getSurchargeLineDescription')->willReturn('Payment terms fee');
        $this->config->method('getSurchargeTaxRate')->willReturn(0.0);
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
        $this->expectExceptionMessage('Pricing API call failed (status 401): X-API-Key is incorrect or has expired [Trace ID: abc123]');

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
        $this->expectExceptionMessage('Pricing API call failed (status 400): Transport error: timeout');

        $this->calculator->calculate(1000.0, 60, 'NO', 'NOK');
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

    public function testPayloadIncludesExplicitZeroCap(): void
    {
        $this->stubCommonConfig(SurchargeType::FIXED);
        $this->stubSurchargeConfig(0, 10, 0.0);
        $this->stubFixedCurrency('NOK');

        $this->adapter->expects($this->once())
            ->method('execute')
            ->with(
                '/v1/pricing/order/fee',
                $this->callback(function ($payload) {
                    return array_key_exists('cap', $payload['buyer_fee_share'])
                        && $payload['buyer_fee_share']['cap'] === 0.0;
                })
            )
            ->willReturn(['buyer_fee_share' => 0]);

        $this->calculator->calculate(1000.0, 30, 'NO', 'NOK');
    }

    // ── Differential mode (reference_terms) ──────────────────────────

    public function testPayloadIncludesReferenceTermsWhenDifferential(): void
    {
        $this->stubCommonConfig(SurchargeType::PERCENTAGE, true);
        $this->config->method('getDefaultPaymentTerm')->willReturn(30);
        $this->stubSurchargeConfig(75);

        $this->adapter->expects($this->once())
            ->method('execute')
            ->with(
                '/v1/pricing/order/fee',
                $this->callback(function ($payload) {
                    $ref = $payload['buyer_fee_share']['reference_terms'] ?? null;
                    return is_array($ref)
                        && $ref['type'] === 'NET_TERMS'
                        && $ref['duration_days'] === 30;
                })
            )
            ->willReturn(['buyer_fee_share' => 11.25]);

        $result = $this->calculator->calculate(1000.0, 60, 'NO', 'NOK');

        $this->assertEquals(11.25, $result['amount']);
    }

    public function testPayloadOmitsReferenceTermsWhenNotDifferential(): void
    {
        $this->stubCommonConfig(SurchargeType::PERCENTAGE, false);
        $this->stubSurchargeConfig(50);

        $this->adapter->expects($this->once())
            ->method('execute')
            ->with(
                '/v1/pricing/order/fee',
                $this->callback(function ($payload) {
                    return !array_key_exists('reference_terms', $payload['buyer_fee_share']);
                })
            )
            ->willReturn(['buyer_fee_share' => 0]);

        $this->calculator->calculate(1000.0, 60, 'NO', 'NOK');
    }

    // ── End of month terms ───────────────────────────────────────────

    public function testEndOfMonthTermsPassedToApi(): void
    {
        $this->config->method('getSurchargeType')->willReturn(SurchargeType::PERCENTAGE);
        $this->config->method('isSurchargeDifferential')->willReturn(true);
        $this->config->method('getDefaultPaymentTerm')->willReturn(30);
        $this->config->method('getPaymentTermsType')->willReturn('end_of_month');
        $this->config->method('getSurchargeLineDescription')->willReturn('Payment terms fee');
        $this->config->method('getSurchargeTaxRate')->willReturn(0.0);
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
        $this->config->method('getSurchargeTaxRate')->willReturn(25.0);
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
}
