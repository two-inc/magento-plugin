<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Service\Order;

use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Model\Quote;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\Config\RepositoryInterface as ConfigRepository;
use Two\Gateway\Api\Log\RepositoryInterface as LogRepository;
use Two\Gateway\Service\Order\SurchargeCalculator;
use Two\Gateway\Service\Order\SurchargeDisplay;
use Two\Gateway\Service\Order\SurchargeTaxCalculator;
use Two\Gateway\Service\Order\TermSurchargePreview;

/**
 * The term chips must be able to show gross, so every preview entry carries
 * net AND gross. The tax rate is destination-aware when a surcharge tax class
 * is configured, and the pricing result's own flat rate otherwise.
 */
class TermSurchargePreviewTest extends TestCase
{
    /** @var ConfigRepository|\PHPUnit\Framework\MockObject\MockObject */
    private $config;

    /** @var SurchargeCalculator|\PHPUnit\Framework\MockObject\MockObject */
    private $calculator;

    /** @var SurchargeTaxCalculator|\PHPUnit\Framework\MockObject\MockObject */
    private $taxCalculator;

    /** @var LogRepository|\PHPUnit\Framework\MockObject\MockObject */
    private $logRepository;

    /** @var TermSurchargePreview */
    private $preview;

    protected function setUp(): void
    {
        $this->config = $this->createMock(ConfigRepository::class);
        $this->calculator = $this->createMock(SurchargeCalculator::class);
        $this->taxCalculator = $this->createMock(SurchargeTaxCalculator::class);
        $this->logRepository = $this->createMock(LogRepository::class);

        $this->preview = new TermSurchargePreview(
            $this->config,
            $this->calculator,
            $this->taxCalculator,
            $this->createMock(SurchargeDisplay::class),
            $this->logRepository
        );
    }

    private function stubTerm(float $net, float $flatRate): void
    {
        $this->calculator->method('calculate')->willReturn([
            'amount' => $net,
            'tax_rate' => $flatRate,
            'description' => 'Payment terms fee',
        ]);
    }

    private function build(): array
    {
        return $this->preview->build(
            $this->createMock(Quote::class),
            1000.0,
            [30],
            'NO',
            'NOK',
            1,
            'test'
        );
    }

    public function testGrossUsesTheEngineRateWhenATaxClassIsConfigured(): void
    {
        $this->config->method('getSurchargeTaxClassId')->willReturn(4);
        $this->taxCalculator->method('resolveRateForQuote')->willReturn(25.0);
        // A different flat rate on the pricing result, to prove it loses.
        $this->stubTerm(100.0, 21.0);

        $this->assertSame(
            [['days' => 30, 'net' => 100.0, 'gross' => 125.0]],
            $this->build()
        );
    }

    public function testGrossFallsBackToTheFlatRateWithNoTaxClassConfigured(): void
    {
        $this->config->method('getSurchargeTaxClassId')->willReturn(null);
        $this->taxCalculator->expects($this->never())->method('resolveRateForQuote');
        $this->stubTerm(100.0, 21.0);

        $this->assertSame(
            [['days' => 30, 'net' => 100.0, 'gross' => 121.0]],
            $this->build()
        );
    }

    /**
     * A chip preview must stay responsive: an engine refusal falls back to the
     * flat rate rather than emptying the chips. The total collector still
     * surfaces the same failure on the authoritative path.
     */
    public function testAnEngineFailureFallsBackToTheFlatRate(): void
    {
        $this->config->method('getSurchargeTaxClassId')->willReturn(4);
        $this->taxCalculator->method('resolveRateForQuote')
            ->willThrowException(new LocalizedException(__('no rule matched')));
        $this->stubTerm(100.0, 21.0);

        $this->assertSame(
            [['days' => 30, 'net' => 100.0, 'gross' => 121.0]],
            $this->build()
        );
    }

    public function testAPerTermPricingFailureZeroesOnlyThatTerm(): void
    {
        $this->config->method('getSurchargeTaxClassId')->willReturn(null);
        $this->calculator->method('calculate')->willReturnCallback(
            static function (float $basis, int $days): array {
                if ($days === 60) {
                    throw new LocalizedException(__('pricing unavailable'));
                }
                return ['amount' => 100.0, 'tax_rate' => 21.0, 'description' => 'fee'];
            }
        );

        $surcharges = $this->preview->build(
            $this->createMock(Quote::class),
            1000.0,
            [30, 60],
            'NO',
            'NOK',
            1,
            'test'
        );

        $this->assertSame(
            [
                ['days' => 30, 'net' => 100.0, 'gross' => 121.0],
                ['days' => 60, 'net' => 0.0, 'gross' => 0.0],
            ],
            $surcharges
        );
    }

    /**
     * Q54: an unrecognised stored method is one condition, not one per term.
     * Read before the tax lookup, so a refused render does no tax work and
     * emits no second error line; the config repository owns the error.
     *
     * @dataProvider corruptStoredMethods
     */
    public function testACorruptStoredMethodZeroesEveryTermWithoutASecondErrorLine(
        int $termCount,
        string $case
    ): void {
        $this->config->method('getSurchargeType')->willThrowException(
            new LocalizedException(__('Invoice purchase with %1 is not available for this order.', 'Two'))
        );
        // A tax class IS configured, so resolveTaxRate() would reach the engine
        // if the guard sat after it — that is what pins the ordering.
        $this->config->method('getSurchargeTaxClassId')->willReturn(4);
        // Neither the tax lookup nor the pricing call may run.
        $this->taxCalculator->expects($this->never())->method('resolveRateForQuote');
        $this->calculator->expects($this->never())->method('calculate');
        $this->logRepository->expects($this->never())->method('addErrorLog');
        $debug = [];
        $this->logRepository->method('addDebugLog')->willReturnCallback(
            function ($type) use (&$debug): void {
                $debug[] = (string)$type;
            }
        );

        $terms = array_slice([30, 60, 90], 0, $termCount);
        $expected = array_map(
            static fn ($days) => ['days' => $days, 'net' => 0.0, 'gross' => 0.0],
            $terms
        );

        $result = $this->preview->build(
            $this->createMock(Quote::class),
            1000.0,
            $terms,
            'NO',
            'NOK',
            1,
            'test'
        );

        $this->assertSame($expected, $result, $case);
        $this->assertCount(1, $debug, 'one debug line whatever the term count: ' . $case);
        $this->assertStringContainsString('unrecognised surcharge method', $debug[0], $case);
    }

    public function corruptStoredMethods(): array
    {
        return [
            [1, 'a single offered term'],
            [3, 'three offered terms still report once, not once per term'],
        ];
    }

    public function testZeroedCarriesBothAmountsSoChipsLeaveTheLoaderState(): void
    {
        $this->assertSame(
            [
                ['days' => 30, 'net' => 0.0, 'gross' => 0.0],
                ['days' => 60, 'net' => 0.0, 'gross' => 0.0],
            ],
            $this->preview->zeroed([30, 60])
        );
    }
}
