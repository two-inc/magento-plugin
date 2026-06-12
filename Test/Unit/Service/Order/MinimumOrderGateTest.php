<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Service\Order;

use Magento\Quote\Api\Data\CartInterface;
use Magento\Quote\Model\Quote;
use Magento\Store\Model\Store;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\BrandRegistryInterface;
use Two\Gateway\Api\CurrencyRatesProviderInterface;
use Two\Gateway\Api\Log\RepositoryInterface as LogRepository;
use Two\Gateway\Service\Order\MinimumOrderGate;

class MinimumOrderGateTest extends TestCase
{
    /** @var BrandRegistryInterface|\PHPUnit\Framework\MockObject\MockObject */
    private $brand;

    /** @var CurrencyRatesProviderInterface|\PHPUnit\Framework\MockObject\MockObject */
    private $ratesProvider;

    /** @var LogRepository|\PHPUnit\Framework\MockObject\MockObject */
    private $logRepository;

    /** @var MinimumOrderGate */
    private $gate;

    protected function setUp(): void
    {
        $this->brand = $this->createMock(BrandRegistryInterface::class);
        $this->ratesProvider = $this->createMock(CurrencyRatesProviderInterface::class);
        $this->logRepository = $this->createMock(LogRepository::class);

        $this->gate = new MinimumOrderGate(
            $this->ratesProvider,
            $this->logRepository
        );
    }

    /**
     * @return Quote|\PHPUnit\Framework\MockObject\MockObject
     */
    private function quote(float $grandTotal, ?string $currency, ?Store $store = null, float $taxAmount = 0.0)
    {
        $quote = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getGrandTotal', 'getQuoteCurrencyCode', 'getStoreId', 'getStore', 'getAllAddresses'])
            ->getMock();
        $quote->method('getGrandTotal')->willReturn($grandTotal);
        $quote->method('getQuoteCurrencyCode')->willReturn($currency);
        $quote->method('getStoreId')->willReturn(1);
        $quote->method('getStore')->willReturn($store);
        $address = new class ($taxAmount) {
            public function __construct(private readonly float $taxAmount)
            {
            }

            public function getTaxAmount(): float
            {
                return $this->taxAmount;
            }
        };
        $quote->method('getAllAddresses')->willReturn([$address]);
        return $quote;
    }

    private function stubMinimum(?array $minimum): void
    {
        $this->brand->method('getMinimumOrder')->willReturn($minimum);
    }

    // ── No minimum configured (vanilla Two brand) ────────────────────

    public function testSatisfiedRegardlessOfAmountWhenBrandHasNoMinimum(): void
    {
        $this->stubMinimum(null);
        $this->ratesProvider->expects($this->never())->method('getRate');

        $this->assertTrue($this->gate->isSatisfied($this->brand, $this->quote(0.01, 'EUR')));
    }

    public function testSatisfiedWhenQuoteIsNull(): void
    {
        $this->stubMinimum(['amount' => 250.0, 'currency' => 'EUR', 'basis' => 'net']);

        $this->assertTrue($this->gate->isSatisfied($this->brand, null));
    }

    // ── Same-currency comparison ─────────────────────────────────────

    public function testNotSatisfiedWhenEurTotalBelowEurMinimum(): void
    {
        $this->stubMinimum(['amount' => 250.0, 'currency' => 'EUR', 'basis' => 'net']);

        $this->assertFalse($this->gate->isSatisfied($this->brand, $this->quote(249.99, 'EUR')));
    }

    public function testSatisfiedWhenEurTotalEqualsEurMinimum(): void
    {
        $this->stubMinimum(['amount' => 250.0, 'currency' => 'EUR', 'basis' => 'net']);

        $this->assertTrue($this->gate->isSatisfied($this->brand, $this->quote(250.0, 'EUR')));
    }

    public function testComparesNetNotGross(): void
    {
        $this->stubMinimum(['amount' => 250.0, 'currency' => 'EUR', 'basis' => 'net']);

        // EUR 302.50 gross with EUR 52.50 tax is exactly EUR 250 net: satisfied
        $this->assertTrue($this->gate->isSatisfied($this->brand, $this->quote(302.50, 'EUR', null, 52.50)));
        // EUR 250 gross with tax is below EUR 250 net: the credit check would
        // decline it, so the gate must hide the method
        $this->assertFalse($this->gate->isSatisfied($this->brand, $this->quote(250.0, 'EUR', null, 43.39)));
    }

    public function testSameCurrencySkipsRateLookup(): void
    {
        $this->stubMinimum(['amount' => 250.0, 'currency' => 'EUR', 'basis' => 'net']);
        $this->ratesProvider->expects($this->never())->method('getRate');

        $this->assertTrue($this->gate->isSatisfied($this->brand, $this->quote(300.0, 'EUR')));
    }

    public function testFallsBackToStoreBaseCurrencyWhenQuoteCurrencyMissing(): void
    {
        $this->stubMinimum(['amount' => 250.0, 'currency' => 'EUR', 'basis' => 'net']);
        $this->ratesProvider->expects($this->never())->method('getRate');

        $store = $this->createMock(Store::class);
        $store->method('getBaseCurrencyCode')->willReturn('EUR');

        $this->assertTrue($this->gate->isSatisfied($this->brand, $this->quote(300.0, null, $store)));
    }

    public function testFailsClosedWhenBasketCurrencyUnresolvable(): void
    {
        $this->stubMinimum(['amount' => 250.0, 'currency' => 'EUR', 'basis' => 'net']);

        // No quote currency and no store: the gate cannot establish what
        // currency the basket is in, so it must not offer the method.
        $this->assertFalse($this->gate->isSatisfied($this->brand, $this->quote(300.0, null)));
    }

    public function testPassesForNonQuoteCartInterface(): void
    {
        $this->stubMinimum(['amount' => 250.0, 'currency' => 'EUR', 'basis' => 'net']);

        // Deliberate: every real checkout flow passes the concrete Quote;
        // an unknown CartInterface impl skips the gate rather than hiding
        // the method (documented in MinimumOrderGate::isSatisfied()).
        $this->assertTrue($this->gate->isSatisfied($this->brand, $this->createMock(CartInterface::class)));
    }

    // ── Cross-currency comparison via store FX rates ─────────────────

    public function testNotSatisfiedWhenConvertedGbpTotalBelowEurMinimum(): void
    {
        $this->stubMinimum(['amount' => 250.0, 'currency' => 'EUR', 'basis' => 'net']);
        $this->ratesProvider->method('getRate')
            ->with('GBP', 'EUR', 1)
            ->willReturn(1.17);

        // £99 -> €115.83 < €250
        $this->assertFalse($this->gate->isSatisfied($this->brand, $this->quote(99.0, 'GBP')));
    }

    public function testSatisfiedWhenConvertedGbpTotalAboveEurMinimum(): void
    {
        $this->stubMinimum(['amount' => 250.0, 'currency' => 'EUR', 'basis' => 'net']);
        $this->ratesProvider->method('getRate')
            ->with('GBP', 'EUR', 1)
            ->willReturn(1.17);

        // £1000 -> €1170 >= €250
        $this->assertTrue($this->gate->isSatisfied($this->brand, $this->quote(1000.0, 'GBP')));
    }

    public function testFailsClosedWhenNoExchangeRateConfigured(): void
    {
        $this->stubMinimum(['amount' => 250.0, 'currency' => 'EUR', 'basis' => 'net']);
        $this->ratesProvider->method('getRate')->willReturn(null);

        $this->assertFalse($this->gate->isSatisfied($this->brand, $this->quote(10000.0, 'SEK')));
    }

    public function testFailsClosedWhenRateIsZero(): void
    {
        $this->stubMinimum(['amount' => 250.0, 'currency' => 'EUR', 'basis' => 'net']);
        $this->ratesProvider->method('getRate')->willReturn(0.0);

        $this->assertFalse($this->gate->isSatisfied($this->brand, $this->quote(10000.0, 'SEK')));
    }

    public function testConvertedTotalComparedAtCurrencyPrecision(): void
    {
        $this->stubMinimum(['amount' => 250.0, 'currency' => 'EUR', 'basis' => 'net']);
        $this->ratesProvider->method('getRate')
            ->with('GBP', 'EUR', 1)
            ->willReturn(1.17);

        // £213.675 x 1.17 = €249.99975 raw; rounded once at the decision
        // boundary it is €250.00 and satisfies the minimum.
        $this->assertTrue($this->gate->isSatisfied($this->brand, $this->quote(213.675, 'GBP')));
    }

    public function testBelowMinimumDrivesTheDeclineHintStrictly(): void
    {
        $this->stubMinimum(['amount' => 250.0, 'currency' => 'EUR', 'basis' => 'net']);

        $this->assertTrue($this->gate->isBelowMinimum($this->brand, 249.99, 'EUR', 1));
        // No tolerance band: at or above the minimum is not "below"
        $this->assertFalse($this->gate->isBelowMinimum($this->brand, 250.0, 'EUR', 1));
        // No minimum configured: never hint
        $this->brand = $this->createMock(BrandRegistryInterface::class);
        $this->brand->method('getMinimumOrder')->willReturn(null);
        $this->assertFalse($this->gate->isBelowMinimum($this->brand, 10.0, 'EUR', 1));
    }

    public function testBelowMinimumFailsSoftWithoutRate(): void
    {
        $this->stubMinimum(['amount' => 250.0, 'currency' => 'EUR', 'basis' => 'net']);
        $this->ratesProvider->method('getRate')->willReturn(null);

        $this->assertFalse($this->gate->isBelowMinimum($this->brand, 10.0, 'SEK', 1));
    }

    public function testMerchantMinimumRaisesTheBar(): void
    {
        // No platform minimum, merchant sets one: it gates alone
        $this->stubMinimum(null);
        $merchantMinimum = ['amount' => 500.0, 'currency' => 'EUR', 'basis' => 'gross'];

        $this->assertFalse($this->gate->isSatisfied($this->brand, $this->quote(499.0, 'EUR'), $merchantMinimum));
        $this->assertTrue($this->gate->isSatisfied($this->brand, $this->quote(500.0, 'EUR'), $merchantMinimum));
    }

    public function testMerchantMinimumAppliesOnTopOfThePlatformFloor(): void
    {
        $this->stubMinimum(['amount' => 250.0, 'currency' => 'EUR', 'basis' => 'net']);
        $merchantMinimum = ['amount' => 400.0, 'currency' => 'EUR', 'basis' => 'net'];

        // Above the floor but below the merchant's own bar: hidden
        $this->assertFalse($this->gate->isSatisfied($this->brand, $this->quote(300.0, 'EUR'), $merchantMinimum));
        $this->assertTrue($this->gate->isSatisfied($this->brand, $this->quote(400.0, 'EUR'), $merchantMinimum));
    }

    public function testGrossBasisComparesGrandTotal(): void
    {
        $this->stubMinimum(['amount' => 250.0, 'currency' => 'EUR', 'basis' => 'gross']);

        // EUR 250 gross with tax included satisfies a GROSS minimum even
        // though its net value is below
        $this->assertTrue($this->gate->isSatisfied($this->brand, $this->quote(250.0, 'EUR', null, 43.39)));
        $this->assertFalse($this->gate->isSatisfied($this->brand, $this->quote(249.99, 'EUR', null, 43.39)));
    }

    public function testMinimumForDisplayConvertsToOrderCurrency(): void
    {
        $this->stubMinimum(['amount' => 250.0, 'currency' => 'EUR', 'basis' => 'net']);
        $this->ratesProvider->method('getRate')
            ->with('EUR', 'GBP', 1)
            ->willReturn(0.86);

        $this->assertSame(
            ['amount' => 215.0, 'basis' => 'net'],
            $this->gate->getMinimumForDisplay($this->brand, 'GBP', 1)
        );
        // No rate: no display value (caller falls back to the generic message)
        $brand = $this->createMock(BrandRegistryInterface::class);
        $brand->method('getMinimumOrder')->willReturn(['amount' => 250.0, 'currency' => 'EUR', 'basis' => 'net']);
        $gate = new MinimumOrderGate($this->createMock(CurrencyRatesProviderInterface::class), $this->logRepository);
        $this->assertNull($gate->getMinimumForDisplay($brand, 'SEK', 1));
    }

    public function testReportsMissingRateOncePerCurrencyPair(): void
    {
        $this->stubMinimum(['amount' => 250.0, 'currency' => 'EUR', 'basis' => 'net']);
        $this->ratesProvider->method('getRate')->willReturn(null);
        $this->logRepository->expects($this->once())->method('addErrorLog');

        $this->gate->isSatisfied($this->brand, $this->quote(100.0, 'SEK'));
        $this->gate->isSatisfied($this->brand, $this->quote(200.0, 'SEK'));
    }
}
