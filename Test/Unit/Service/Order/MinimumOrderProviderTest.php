<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Service\Order;

use PHPUnit\Framework\TestCase;
use Two\Gateway\Service\Merchant\RecordProvider;
use Two\Gateway\Service\Order\MinimumOrderProvider;

class MinimumOrderProviderTest extends TestCase
{
    /** @var RecordProvider|\PHPUnit\Framework\MockObject\MockObject */
    private $recordProvider;

    /** @var MinimumOrderProvider */
    private $provider;

    protected function setUp(): void
    {
        $this->recordProvider = $this->createMock(RecordProvider::class);
        $this->provider = new MinimumOrderProvider($this->recordProvider);
    }

    private function stubRecord(?array $record): void
    {
        $this->recordProvider->method('getRecord')->willReturn($record);
    }

    public function testResolvesMinimumFromMerchantRecord(): void
    {
        $this->stubRecord([
            'min_order_amount' => '250.00',
            'min_order_currency' => 'EUR',
            'min_order_basis' => 'net',
        ]);

        $this->assertSame(
            ['amount' => 250.0, 'currency' => 'EUR', 'basis' => 'net'],
            $this->provider->getMinimum(1)
        );
    }

    public function testNoMinimumWhenRecordOmitsTheTuple(): void
    {
        // The common case: merchant has no minimum configured, the record
        // omits all three fields.
        $this->stubRecord(['id' => 'abc-123']);

        $this->assertNull($this->provider->getMinimum(1));
    }

    public function testPartialTupleResolvesToNoMinimum(): void
    {
        $this->stubRecord([
            'min_order_amount' => '250.00',
            'min_order_currency' => 'EUR',
            // basis missing - never gate on a guessed tax basis
        ]);

        $this->assertNull($this->provider->getMinimum(1));
    }

    public function testNullRecordResolvesToNoMinimum(): void
    {
        // Unresolvable merchant / API blip / no key: RecordProvider yields
        // null and the gate degrades to "no minimum" (the server enforces).
        $this->stubRecord(null);

        $this->assertNull($this->provider->getMinimum(1));
    }

    public function testNormalisesCurrencyCase(): void
    {
        $this->stubRecord([
            'min_order_amount' => '250.00',
            'min_order_currency' => 'eur',
            'min_order_basis' => 'net',
        ]);

        // A lowercase currency must not force the gate's FX branch into
        // permanent fail-closed against uppercase quote currency codes.
        $minimum = $this->provider->getMinimum(1);
        $this->assertSame('EUR', $minimum['currency']);
    }
}
