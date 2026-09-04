<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use Two\Gateway\Model\CurrencyRatesProvider;
use Two\Gateway\Service\Fx\RateTableProvider;

class CurrencyRatesProviderTest extends TestCase
{
    /** @var RateTableProvider|\PHPUnit\Framework\MockObject\MockObject */
    private $rateTableProvider;

    /** @var CurrencyRatesProvider */
    private $provider;

    protected function setUp(): void
    {
        $this->rateTableProvider = $this->createMock(RateTableProvider::class);
        $this->provider = new CurrencyRatesProvider($this->rateTableProvider);
    }

    private function stubTable(array $rates): void
    {
        $this->rateTableProvider->method('getRateTable')->willReturn([
            'rates' => $rates,
            'as_of' => '2026-07-15',
            'fetched_at' => time(),
        ]);
    }

    public function testSameCurrencyIsUnityWithoutTableLookup(): void
    {
        $this->rateTableProvider->expects($this->never())->method('getRateTable');

        $this->assertSame(1.0, $this->provider->getRate('EUR', 'EUR', 1));
    }

    public function testRateToEurReadsThePivotDirectly(): void
    {
        $this->stubTable(['EUR' => 1.0, 'GBP' => 1.17]);

        $this->assertEqualsWithDelta(1.17, $this->provider->getRate('GBP', 'EUR', 1), 1e-9);
    }

    public function testRateFromEurInvertsThePivot(): void
    {
        $this->stubTable(['EUR' => 1.0, 'GBP' => 1.17]);

        $this->assertEqualsWithDelta(1 / 1.17, $this->provider->getRate('EUR', 'GBP', 1), 1e-9);
    }

    public function testCrossCurrencyAmountUsesTheEndpointRateNotTheDirectoryTable(): void
    {
        // The ticket's cross-currency scenario: with the endpoint's
        // EUR-pivot table (1 GBP = 1.17 EUR, 1 NOK = 0.085 EUR), a
        // GBP 100.00 basket converts at 1.17 / 0.085 to NOK 1376.47.
        // The old Magento Directory rate table no longer participates —
        // the provider has no dependency left through which it could.
        $this->stubTable(['EUR' => 1.0, 'GBP' => 1.17, 'NOK' => 0.085]);

        $rate = $this->provider->getRate('GBP', 'NOK', 1);

        $this->assertEqualsWithDelta(13.7647058824, $rate, 1e-9);
        $this->assertSame(1376.47, round(100.00 * $rate, 2));
    }

    public function testCurrencyAbsentFromTableResolvesToNull(): void
    {
        $this->stubTable(['EUR' => 1.0, 'GBP' => 1.17]);

        $this->assertNull($this->provider->getRate('GBP', 'XXX', 1));
        $this->assertNull($this->provider->getRate('XXX', 'GBP', 1));
    }

    public function testNoTableEverFetchedResolvesToNull(): void
    {
        $this->rateTableProvider->method('getRateTable')->willReturn(null);

        $this->assertNull($this->provider->getRate('GBP', 'EUR', 1));
    }

    public function testStoreScopeIsForwardedToTheTableProvider(): void
    {
        $this->rateTableProvider->expects($this->once())->method('getRateTable')
            ->with(7)->willReturn(null);

        $this->provider->getRate('GBP', 'EUR', 7);
    }
}
