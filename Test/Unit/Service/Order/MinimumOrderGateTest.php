<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Service\Order;

use Magento\Quote\Api\Data\CartInterface;
use Magento\Quote\Model\Quote;
use Magento\Store\Model\Store;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\CurrencyRatesProviderInterface;
use Two\Gateway\Api\Log\RepositoryInterface as LogRepository;
use Two\Gateway\Service\Order\MinimumOrderGate;

class MinimumOrderGateTest extends TestCase
{
    private const EUR_250_NET = ['amount' => 250.0, 'currency' => 'EUR', 'basis' => 'net'];

    /** @var CurrencyRatesProviderInterface|\PHPUnit\Framework\MockObject\MockObject */
    private $ratesProvider;

    /** @var LogRepository|\PHPUnit\Framework\MockObject\MockObject */
    private $logRepository;

    /** @var MinimumOrderGate */
    private $gate;

    protected function setUp(): void
    {
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

    // ── No minimum configured (API reports none) ─────────────────────

    public function testSatisfiedRegardlessOfAmountWhenNoMinimum(): void
    {
        $this->ratesProvider->expects($this->never())->method('getRate');

        $this->assertTrue($this->gate->isSatisfied(null, $this->quote(0.01, 'EUR')));
    }

    public function testSatisfiedWhenQuoteIsNull(): void
    {
        $this->assertTrue($this->gate->isSatisfied(self::EUR_250_NET, null));
    }

    // ── Same-currency comparison ─────────────────────────────────────

    public function testNotSatisfiedWhenEurTotalBelowEurMinimum(): void
    {
        $this->assertFalse($this->gate->isSatisfied(self::EUR_250_NET, $this->quote(249.99, 'EUR')));
    }

    public function testSatisfiedWhenEurTotalEqualsEurMinimum(): void
    {
        $this->assertTrue($this->gate->isSatisfied(self::EUR_250_NET, $this->quote(250.0, 'EUR')));
    }

    public function testComparesNetNotGross(): void
    {
        // EUR 302.50 gross with EUR 52.50 tax is exactly EUR 250 net: satisfied
        $this->assertTrue($this->gate->isSatisfied(self::EUR_250_NET, $this->quote(302.50, 'EUR', null, 52.50)));
        // EUR 250 gross with tax is below EUR 250 net: the credit check would
        // decline it, so the gate must hide the method
        $this->assertFalse($this->gate->isSatisfied(self::EUR_250_NET, $this->quote(250.0, 'EUR', null, 43.39)));
    }

    public function testSameCurrencySkipsRateLookup(): void
    {
        $this->ratesProvider->expects($this->never())->method('getRate');

        $this->assertTrue($this->gate->isSatisfied(self::EUR_250_NET, $this->quote(300.0, 'EUR')));
    }

    public function testFallsBackToStoreBaseCurrencyWhenQuoteCurrencyMissing(): void
    {
        $this->ratesProvider->expects($this->never())->method('getRate');

        $store = $this->createMock(Store::class);
        $store->method('getBaseCurrencyCode')->willReturn('EUR');

        $this->assertTrue($this->gate->isSatisfied(self::EUR_250_NET, $this->quote(300.0, null, $store)));
    }

    public function testFailsClosedWhenBasketCurrencyUnresolvable(): void
    {
        // No quote currency and no store: the gate cannot establish what
        // currency the basket is in, so it must not offer the method.
        $this->assertFalse($this->gate->isSatisfied(self::EUR_250_NET, $this->quote(300.0, null)));
    }

    public function testPassesForNonQuoteCartInterface(): void
    {
        // Deliberate: every real checkout flow passes the concrete Quote;
        // an unknown CartInterface impl skips the gate rather than hiding
        // the method (documented in MinimumOrderGate::isSatisfied()).
        $this->assertTrue($this->gate->isSatisfied(self::EUR_250_NET, $this->createMock(CartInterface::class)));
    }

    // ── Cross-currency comparison via store FX rates ─────────────────

    public function testNotSatisfiedWhenConvertedGbpTotalBelowEurMinimum(): void
    {
        $this->ratesProvider->method('getRate')
            ->with('GBP', 'EUR', 1)
            ->willReturn(1.17);

        // £99 -> €115.83 < €250
        $this->assertFalse($this->gate->isSatisfied(self::EUR_250_NET, $this->quote(99.0, 'GBP')));
    }

    public function testSatisfiedWhenConvertedGbpTotalAboveEurMinimum(): void
    {
        $this->ratesProvider->method('getRate')
            ->with('GBP', 'EUR', 1)
            ->willReturn(1.17);

        // £1000 -> €1170 >= €250
        $this->assertTrue($this->gate->isSatisfied(self::EUR_250_NET, $this->quote(1000.0, 'GBP')));
    }

    public function testFailsClosedWhenNoExchangeRateConfigured(): void
    {
        $this->ratesProvider->method('getRate')->willReturn(null);

        $this->assertFalse($this->gate->isSatisfied(self::EUR_250_NET, $this->quote(10000.0, 'SEK')));
    }

    public function testFailsClosedWhenRateIsZero(): void
    {
        $this->ratesProvider->method('getRate')->willReturn(0.0);

        $this->assertFalse($this->gate->isSatisfied(self::EUR_250_NET, $this->quote(10000.0, 'SEK')));
    }

    public function testConvertedTotalComparedAtCurrencyPrecision(): void
    {
        $this->ratesProvider->method('getRate')
            ->with('GBP', 'EUR', 1)
            ->willReturn(1.17);

        // £213.675 x 1.17 = €249.99975 raw; rounded once at the decision
        // boundary it is €250.00 and satisfies the minimum.
        $this->assertTrue($this->gate->isSatisfied(self::EUR_250_NET, $this->quote(213.675, 'GBP')));
    }

    public function testBelowMinimumDrivesTheDeclineHintStrictly(): void
    {
        $this->assertTrue($this->gate->isBelowMinimum(self::EUR_250_NET, 249.99, 'EUR', 1));
        // No tolerance band: at or above the minimum is not "below"
        $this->assertFalse($this->gate->isBelowMinimum(self::EUR_250_NET, 250.0, 'EUR', 1));
        // No minimum configured: never hint
        $this->assertFalse($this->gate->isBelowMinimum(null, 10.0, 'EUR', 1));
    }

    public function testBelowMinimumFailsSoftWithoutRate(): void
    {
        $this->ratesProvider->method('getRate')->willReturn(null);

        $this->assertFalse($this->gate->isBelowMinimum(self::EUR_250_NET, 10.0, 'SEK', 1));
    }

    public function testMerchantMinimumRaisesTheBar(): void
    {
        // No platform minimum, merchant sets one: it gates alone
        $merchantMinimum = ['amount' => 500.0, 'currency' => 'EUR', 'basis' => 'gross'];

        $this->assertFalse($this->gate->isSatisfied(null, $this->quote(499.0, 'EUR'), $merchantMinimum));
        $this->assertTrue($this->gate->isSatisfied(null, $this->quote(500.0, 'EUR'), $merchantMinimum));
    }

    public function testMerchantMinimumAppliesOnTopOfThePlatformFloor(): void
    {
        $merchantMinimum = ['amount' => 400.0, 'currency' => 'EUR', 'basis' => 'net'];

        // Above the floor but below the merchant's own bar: hidden
        $this->assertFalse($this->gate->isSatisfied(self::EUR_250_NET, $this->quote(300.0, 'EUR'), $merchantMinimum));
        $this->assertTrue($this->gate->isSatisfied(self::EUR_250_NET, $this->quote(400.0, 'EUR'), $merchantMinimum));
    }

    // ── Split fail policy: platform floor closed, merchant minimum open ─

    public function testPlatformFloorFailsClosedEvenWhenMerchantMinimumSatisfied(): void
    {
        // No rate for the platform floor's currency: blocked regardless of
        // the merchant minimum being absent or satisfiable.
        $this->ratesProvider->method('getRate')->willReturn(null);

        $merchantMinimum = ['amount' => 100.0, 'currency' => 'SEK', 'basis' => 'net'];

        $this->assertFalse($this->gate->isSatisfied(self::EUR_250_NET, $this->quote(10000.0, 'SEK'), $merchantMinimum));
    }

    public function testMerchantMinimumFailsOpenWhenNoExchangeRateConfigured(): void
    {
        // Platform floor is same-currency and satisfied; the merchant's own
        // minimum is in a currency with no configured rate. That is a local
        // preference we cannot evaluate — it must not block checkout.
        $this->ratesProvider->method('getRate')
            ->with('EUR', 'NOK', 1)
            ->willReturn(null);

        $merchantMinimum = ['amount' => 5000.0, 'currency' => 'NOK', 'basis' => 'net'];

        $this->assertTrue($this->gate->isSatisfied(self::EUR_250_NET, $this->quote(300.0, 'EUR'), $merchantMinimum));
    }

    public function testMerchantMinimumFailsOpenWhenRateIsZero(): void
    {
        $this->ratesProvider->method('getRate')
            ->with('EUR', 'NOK', 1)
            ->willReturn(0.0);

        $merchantMinimum = ['amount' => 5000.0, 'currency' => 'NOK', 'basis' => 'net'];

        $this->assertTrue($this->gate->isSatisfied(self::EUR_250_NET, $this->quote(300.0, 'EUR'), $merchantMinimum));
    }

    public function testMerchantMinimumFailsOpenWhenBasketCurrencyUnresolvable(): void
    {
        // No quote currency and no store: with no platform floor in play the
        // merchant's own minimum cannot be evaluated — it fails open.
        $merchantMinimum = ['amount' => 500.0, 'currency' => 'EUR', 'basis' => 'net'];

        $this->assertTrue($this->gate->isSatisfied(null, $this->quote(300.0, null), $merchantMinimum));
    }

    public function testMerchantMinimumFailOpenLogsDebugNotError(): void
    {
        $this->ratesProvider->method('getRate')->willReturn(null);
        $this->logRepository->expects($this->never())->method('addErrorLog');
        $this->logRepository->expects($this->once())->method('addDebugLog');

        $merchantMinimum = ['amount' => 5000.0, 'currency' => 'NOK', 'basis' => 'net'];

        $this->gate->isSatisfied(null, $this->quote(300.0, 'EUR'), $merchantMinimum);
        $this->gate->isSatisfied(null, $this->quote(400.0, 'EUR'), $merchantMinimum);
    }

    public function testGrossBasisComparesGrandTotal(): void
    {
        $minimum = ['amount' => 250.0, 'currency' => 'EUR', 'basis' => 'gross'];

        // EUR 250 gross with tax included satisfies a GROSS minimum even
        // though its net value is below
        $this->assertTrue($this->gate->isSatisfied($minimum, $this->quote(250.0, 'EUR', null, 43.39)));
        $this->assertFalse($this->gate->isSatisfied($minimum, $this->quote(249.99, 'EUR', null, 43.39)));
    }

    public function testMinimumForDisplayConvertsToOrderCurrency(): void
    {
        $this->ratesProvider->method('getRate')
            ->with('EUR', 'GBP', 1)
            ->willReturn(0.86);

        $this->assertSame(
            ['amount' => 215.0, 'basis' => 'net'],
            $this->gate->getMinimumForDisplay(self::EUR_250_NET, 'GBP', 1)
        );
        // No rate: no display value (caller falls back to the generic message)
        $gate = new MinimumOrderGate($this->createMock(CurrencyRatesProviderInterface::class), $this->logRepository);
        $this->assertNull($gate->getMinimumForDisplay(self::EUR_250_NET, 'SEK', 1));
    }

    public function testReportsMissingRateOncePerCurrencyPair(): void
    {
        $this->ratesProvider->method('getRate')->willReturn(null);
        $this->logRepository->expects($this->once())->method('addErrorLog');

        $this->gate->isSatisfied(self::EUR_250_NET, $this->quote(100.0, 'SEK'));
        $this->gate->isSatisfied(self::EUR_250_NET, $this->quote(200.0, 'SEK'));
    }
}
