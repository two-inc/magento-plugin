<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Service\Order;

use Magento\Framework\HTTP\Client\Curl;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\Config\RepositoryInterface as ConfigRepository;
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

    /** @var SurchargeCalculator */
    private $calculator;

    protected function setUp(): void
    {
        $this->config = $this->createMock(ConfigRepository::class);
        $this->adapter = $this->getMockBuilder(Adapter::class)
            ->setConstructorArgs([
                $this->config,
                $this->createMock(Curl::class),
                $this->createMock(LogRepository::class),
            ])
            ->onlyMethods(['execute'])
            ->getMock();

        $log = $this->createMock(LogRepository::class);

        $this->calculator = new SurchargeCalculator($this->config, $this->adapter, $log);
    }

    private function stubFeeResponse(float $totalFee, int $callIndex = 0): void
    {
        $this->adapter->expects($this->at($callIndex))
            ->method('execute')
            ->willReturn(['total_fee' => $totalFee]);
    }

    private function stubSurchargeConfig(int $percentage = 0, int $fixed = 0, float $limit = 0): void
    {
        $this->config->method('getSurchargeConfig')->willReturn([
            'percentage' => $percentage,
            'fixed' => $fixed,
            'limit' => $limit,
        ]);
    }

    // ── No surcharge ─────────────────────────────────────────────────

    public function testReturnsZeroWhenSurchargeTypeIsNone(): void
    {
        $this->config->method('getSurchargeType')->willReturn(SurchargeType::NONE);

        $result = $this->calculator->calculate(1000.0, 30, 'NO');

        $this->assertEquals(0.0, $result['amount']);
        $this->assertEquals('', $result['description']);
    }

    // ── Percentage surcharge ─────────────────────────────────────────

    public function testPercentageSurcharge(): void
    {
        $this->config->method('getSurchargeType')->willReturn(SurchargeType::PERCENTAGE);
        $this->config->method('isSurchargeDifferential')->willReturn(false);
        $this->config->method('getPaymentTermsType')->willReturn('standard');
        $this->config->method('getSurchargeLineDescription')->willReturn('Payment terms fee');
        $this->config->method('getSurchargeTaxRate')->willReturn(0.0);
        $this->stubSurchargeConfig(50);

        // Merchant fee is 35.00
        $this->adapter->method('execute')->willReturn(['total_fee' => 35.0]);

        $result = $this->calculator->calculate(1000.0, 60, 'NO');

        // 50% of 35.00 = 17.50
        $this->assertEquals(17.50, $result['amount']);
    }

    // ── Fixed fee surcharge ──────────────────────────────────────────

    public function testFixedFeeSurcharge(): void
    {
        $this->config->method('getSurchargeType')->willReturn(SurchargeType::FIXED);
        $this->config->method('isSurchargeDifferential')->willReturn(false);
        $this->config->method('getPaymentTermsType')->willReturn('standard');
        $this->config->method('getSurchargeLineDescription')->willReturn('Payment terms fee');
        $this->config->method('getSurchargeTaxRate')->willReturn(0.0);
        $this->stubSurchargeConfig(0, 15);

        $this->adapter->method('execute')->willReturn(['total_fee' => 35.0]);

        $result = $this->calculator->calculate(1000.0, 60, 'NO');

        $this->assertEquals(15.0, $result['amount']);
    }

    // ── Fixed + percentage combined ──────────────────────────────────

    public function testFixedAndPercentageCombined(): void
    {
        $this->config->method('getSurchargeType')->willReturn(SurchargeType::FIXED_AND_PERCENTAGE);
        $this->config->method('isSurchargeDifferential')->willReturn(false);
        $this->config->method('getPaymentTermsType')->willReturn('standard');
        $this->config->method('getSurchargeLineDescription')->willReturn('Payment terms fee');
        $this->config->method('getSurchargeTaxRate')->willReturn(0.0);
        $this->stubSurchargeConfig(50, 5);

        $this->adapter->method('execute')->willReturn(['total_fee' => 30.0]);

        $result = $this->calculator->calculate(1000.0, 60, 'NO');

        // 50% of 30 = 15 + fixed 5 = 20
        $this->assertEquals(20.0, $result['amount']);
    }

    // ── Limit cap ────────────────────────────────────────────────────

    public function testLimitCapsTheSurcharge(): void
    {
        $this->config->method('getSurchargeType')->willReturn(SurchargeType::FIXED_AND_PERCENTAGE);
        $this->config->method('isSurchargeDifferential')->willReturn(false);
        $this->config->method('getPaymentTermsType')->willReturn('standard');
        $this->config->method('getSurchargeLineDescription')->willReturn('Payment terms fee');
        $this->config->method('getSurchargeTaxRate')->willReturn(0.0);
        $this->stubSurchargeConfig(50, 25, 30);

        // 50% of 100 = 50 + 25 = 75, capped to 30
        $this->adapter->method('execute')->willReturn(['total_fee' => 100.0]);

        $result = $this->calculator->calculate(5000.0, 90, 'NO');

        $this->assertEquals(30.0, $result['amount']);
    }

    // ── Ceiling rounding to cent ─────────────────────────────────────

    public function testRoundsUpToNextCent(): void
    {
        $this->config->method('getSurchargeType')->willReturn(SurchargeType::PERCENTAGE);
        $this->config->method('isSurchargeDifferential')->willReturn(false);
        $this->config->method('getPaymentTermsType')->willReturn('standard');
        $this->config->method('getSurchargeLineDescription')->willReturn('Payment terms fee');
        $this->config->method('getSurchargeTaxRate')->willReturn(0.0);
        $this->stubSurchargeConfig(33);

        // 33% of 17.0 = 5.61
        $this->adapter->method('execute')->willReturn(['total_fee' => 17.0]);

        $result = $this->calculator->calculate(1000.0, 30, 'NO');

        $this->assertEquals(5.61, $result['amount']);
    }

    public function testRoundsUpFractionalCent(): void
    {
        $this->config->method('getSurchargeType')->willReturn(SurchargeType::PERCENTAGE);
        $this->config->method('isSurchargeDifferential')->willReturn(false);
        $this->config->method('getPaymentTermsType')->willReturn('standard');
        $this->config->method('getSurchargeLineDescription')->willReturn('Payment terms fee');
        $this->config->method('getSurchargeTaxRate')->willReturn(0.0);
        $this->stubSurchargeConfig(25);

        // 25% of 17.01 = 4.2525, rounds up to 4.26
        $this->adapter->method('execute')->willReturn(['total_fee' => 17.01]);

        $result = $this->calculator->calculate(1000.0, 30, 'NO');

        $this->assertEquals(4.26, $result['amount']);
    }

    // ── Differential mode ────────────────────────────────────────────

    public function testDifferentialModeDefaultTermReturnsZero(): void
    {
        $this->config->method('getSurchargeType')->willReturn(SurchargeType::PERCENTAGE);
        $this->config->method('isSurchargeDifferential')->willReturn(true);
        $this->config->method('getDefaultPaymentTerm')->willReturn(30);
        $this->config->method('getPaymentTermsType')->willReturn('standard');
        $this->config->method('getSurchargeLineDescription')->willReturn('Payment terms fee');
        $this->config->method('getSurchargeTaxRate')->willReturn(0.0);
        $this->stubSurchargeConfig(50);

        // Buyer selects default term — zero surcharge regardless of config
        $result = $this->calculator->calculate(1000.0, 30, 'NO');

        $this->assertEquals(0.0, $result['amount']);
    }

    public function testDifferentialModeExtendedTermUsesFeeDelta(): void
    {
        $this->config->method('getSurchargeType')->willReturn(SurchargeType::PERCENTAGE);
        $this->config->method('isSurchargeDifferential')->willReturn(true);
        $this->config->method('getDefaultPaymentTerm')->willReturn(30);
        $this->config->method('getPaymentTermsType')->willReturn('standard');
        $this->config->method('getSurchargeLineDescription')->willReturn('Payment terms fee');
        $this->config->method('getSurchargeTaxRate')->willReturn(0.0);
        $this->stubSurchargeConfig(75);

        // Two API calls: first for selected term (60 days), then for default (30 days)
        $this->adapter->method('execute')
            ->willReturnOnConsecutiveCalls(
                ['total_fee' => 35.0],  // 60-day fee
                ['total_fee' => 20.0]   // 30-day fee
            );

        // Delta = 35 - 20 = 15. 75% of 15 = 11.25
        $result = $this->calculator->calculate(1000.0, 60, 'NO');

        $this->assertEquals(11.25, $result['amount']);
    }

    // ── End of month terms ───────────────────────────────────────────

    public function testEndOfMonthTermsPassedToApi(): void
    {
        $this->config->method('getSurchargeType')->willReturn(SurchargeType::PERCENTAGE);
        $this->config->method('isSurchargeDifferential')->willReturn(false);
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
                        && $payload['order_terms']['duration_days'] === 60;
                })
            )
            ->willReturn(['total_fee' => 40.0]);

        $result = $this->calculator->calculate(1000.0, 60, 'NO');

        $this->assertEquals(40.0, $result['amount']);
    }

    // ── Tax rate and description ─────────────────────────────────────

    public function testReturnsTaxRateAndDescription(): void
    {
        $this->config->method('getSurchargeType')->willReturn(SurchargeType::FIXED);
        $this->config->method('isSurchargeDifferential')->willReturn(false);
        $this->config->method('getPaymentTermsType')->willReturn('standard');
        $this->config->method('getSurchargeLineDescription')->willReturn('Extended terms fee');
        $this->config->method('getSurchargeTaxRate')->willReturn(25.0);
        $this->stubSurchargeConfig(0, 10);

        $this->adapter->method('execute')->willReturn(['total_fee' => 30.0]);

        $result = $this->calculator->calculate(1000.0, 30, 'NO');

        $this->assertEquals(10.0, $result['amount']);
        $this->assertEquals(25.0, $result['tax_rate']);
        $this->assertEquals('Extended terms fee', $result['description']);
    }
}
