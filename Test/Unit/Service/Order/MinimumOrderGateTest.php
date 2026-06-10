<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Service\Order;

use Magento\Quote\Model\Quote;
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

    /** @var MinimumOrderGate */
    private $gate;

    protected function setUp(): void
    {
        $this->brand = $this->createMock(BrandRegistryInterface::class);
        $this->ratesProvider = $this->createMock(CurrencyRatesProviderInterface::class);

        $this->gate = new MinimumOrderGate(
            $this->ratesProvider,
            $this->createMock(LogRepository::class)
        );
    }

    /**
     * @return Quote|\PHPUnit\Framework\MockObject\MockObject
     */
    private function quote(float $grandTotal, string $currency)
    {
        $quote = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getGrandTotal', 'getQuoteCurrencyCode', 'getStoreId'])
            ->getMock();
        $quote->method('getGrandTotal')->willReturn($grandTotal);
        $quote->method('getQuoteCurrencyCode')->willReturn($currency);
        $quote->method('getStoreId')->willReturn(1);
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
        $this->stubMinimum(['amount' => 250.0, 'currency' => 'EUR']);

        $this->assertTrue($this->gate->isSatisfied($this->brand, null));
    }

    // ── Same-currency comparison ─────────────────────────────────────

    public function testNotSatisfiedWhenEurTotalBelowEurMinimum(): void
    {
        $this->stubMinimum(['amount' => 250.0, 'currency' => 'EUR']);

        $this->assertFalse($this->gate->isSatisfied($this->brand, $this->quote(249.99, 'EUR')));
    }

    public function testSatisfiedWhenEurTotalEqualsEurMinimum(): void
    {
        $this->stubMinimum(['amount' => 250.0, 'currency' => 'EUR']);

        $this->assertTrue($this->gate->isSatisfied($this->brand, $this->quote(250.0, 'EUR')));
    }

    public function testSameCurrencySkipsRateLookup(): void
    {
        $this->stubMinimum(['amount' => 250.0, 'currency' => 'EUR']);
        $this->ratesProvider->expects($this->never())->method('getRate');

        $this->gate->isSatisfied($this->brand, $this->quote(300.0, 'EUR'));
    }

    // ── Cross-currency comparison via store FX rates ─────────────────

    public function testNotSatisfiedWhenConvertedGbpTotalBelowEurMinimum(): void
    {
        $this->stubMinimum(['amount' => 250.0, 'currency' => 'EUR']);
        $this->ratesProvider->method('getRate')
            ->with('GBP', 'EUR', 1)
            ->willReturn(1.17);

        // £99 -> €115.83 < €250
        $this->assertFalse($this->gate->isSatisfied($this->brand, $this->quote(99.0, 'GBP')));
    }

    public function testSatisfiedWhenConvertedGbpTotalAboveEurMinimum(): void
    {
        $this->stubMinimum(['amount' => 250.0, 'currency' => 'EUR']);
        $this->ratesProvider->method('getRate')
            ->with('GBP', 'EUR', 1)
            ->willReturn(1.17);

        // £1000 -> €1170 >= €250
        $this->assertTrue($this->gate->isSatisfied($this->brand, $this->quote(1000.0, 'GBP')));
    }

    public function testFailsClosedWhenNoExchangeRateConfigured(): void
    {
        $this->stubMinimum(['amount' => 250.0, 'currency' => 'EUR']);
        $this->ratesProvider->method('getRate')->willReturn(null);

        $this->assertFalse($this->gate->isSatisfied($this->brand, $this->quote(10000.0, 'SEK')));
    }
}
