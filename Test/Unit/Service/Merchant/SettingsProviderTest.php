<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Service\Merchant;

use PHPUnit\Framework\TestCase;
use Two\Gateway\Service\Merchant\RecordProvider;
use Two\Gateway\Service\Merchant\SettingsProvider;

class SettingsProviderTest extends TestCase
{
    /** @var RecordProvider|\PHPUnit\Framework\MockObject\MockObject */
    private $recordProvider;

    /** @var SettingsProvider */
    private $provider;

    protected function setUp(): void
    {
        $this->recordProvider = $this->createMock(RecordProvider::class);
        $this->provider = new SettingsProvider($this->recordProvider);
    }

    private function stubRecord(?array $record): void
    {
        $this->recordProvider->method('getRecord')->willReturn($record);
    }

    // --- getAvailableTerms ---

    public function testAvailableTermsAreIntsSortedAscending(): void
    {
        $this->stubRecord(['available_terms' => [90, 30, 60]]);

        $this->assertSame([30, 60, 90], $this->provider->getAvailableTerms(1));
    }

    public function testAvailableTermsDropsNonPositiveAndDedupes(): void
    {
        $this->stubRecord(['available_terms' => [30, 0, -5, 30, 60]]);

        $this->assertSame([30, 60], $this->provider->getAvailableTerms(1));
    }

    public function testAvailableTermsEmptyWhenRecordUnresolved(): void
    {
        $this->stubRecord(null);

        $this->assertSame([], $this->provider->getAvailableTerms(1));
    }

    public function testAvailableTermsEmptyWhenFieldMissingOrNotArray(): void
    {
        $this->stubRecord(['id' => 'abc-123']);

        $this->assertSame([], $this->provider->getAvailableTerms(1));
    }

    // --- getSurchargeLimit ---

    public function testSurchargeLimitResolvedFromRecord(): void
    {
        $this->stubRecord([
            'surcharge_limit_amount' => '25.00',
            'surcharge_limit_currency' => 'EUR',
        ]);

        $this->assertSame(
            ['amount' => 25.0, 'currency' => 'EUR'],
            $this->provider->getSurchargeLimit(1)
        );
    }

    public function testSurchargeLimitNullWhenBothFieldsAbsent(): void
    {
        // Both fields travel together; absent = no cap (unrestricted).
        $this->stubRecord(['id' => 'abc-123']);

        $this->assertNull($this->provider->getSurchargeLimit(1));
    }

    public function testSurchargeLimitNullOnPartialTuple(): void
    {
        $this->stubRecord(['surcharge_limit_amount' => '25.00']);

        $this->assertNull($this->provider->getSurchargeLimit(1));
    }

    public function testSurchargeLimitNormalisesCurrencyCase(): void
    {
        $this->stubRecord([
            'surcharge_limit_amount' => '25.00',
            'surcharge_limit_currency' => 'eur',
        ]);

        $limit = $this->provider->getSurchargeLimit(1);
        $this->assertSame('EUR', $limit['currency']);
    }

    public function testSurchargeLimitNullWhenRecordUnresolved(): void
    {
        $this->stubRecord(null);

        $this->assertNull($this->provider->getSurchargeLimit(1));
    }

    // --- getDefaultTerm ---

    public function testDefaultTermFromDueInDays(): void
    {
        $this->stubRecord(['due_in_days' => 30]);

        $this->assertSame(30, $this->provider->getDefaultTerm(1));
    }

    public function testDefaultTermNullWhenAbsentOrNonPositive(): void
    {
        $this->stubRecord(['due_in_days' => 0]);

        $this->assertNull($this->provider->getDefaultTerm(1));
    }

    public function testDefaultTermNullWhenRecordUnresolved(): void
    {
        $this->stubRecord(null);

        $this->assertNull($this->provider->getDefaultTerm(1));
    }
}
