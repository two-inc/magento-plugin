<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Service\Merchant;

use PHPUnit\Framework\TestCase;
use Two\Gateway\Service\Merchant\RecordProvider;
use Two\Gateway\Service\Merchant\SupportedCountriesProvider;

/**
 * TWO-40: the buyer-country allowlist is opt-in. Every merchant that has
 * configured no restriction has no field in the record at all, so the
 * absent / null / empty cases must allow every country — a fail-closed
 * reading would withdraw the payment method for the entire install base.
 */
class SupportedCountriesProviderTest extends TestCase
{
    /**
     * @param array<string,mixed>|null $record
     * @dataProvider allowlistCases
     */
    public function testCountryIsAllowedOnlyWhenTheRecordSaysSo(
        ?array $record,
        string $country,
        bool $expected,
        string $case
    ): void {
        $recordProvider = $this->createMock(RecordProvider::class);
        $recordProvider->method('getRecord')->willReturn($record);

        $provider = new SupportedCountriesProvider($recordProvider);

        $this->assertSame($expected, $provider->isAllowed($country), $case);
    }

    /**
     * @return array<string, array{0: array<string,mixed>|null, 1: string, 2: bool, 3: string}>
     */
    public static function allowlistCases(): array
    {
        return [
            'field absent' =>
                [['min_order_amount' => 100], 'NO', true, 'no field means no restriction'],
            'field null' =>
                [['supported_buyer_countries' => null], 'NO', true, 'null means no restriction'],
            'empty array' =>
                [['supported_buyer_countries' => []], 'NO', true, 'empty list means no restriction'],
            'blank entries only' =>
                [['supported_buyer_countries' => ['', '  ']], 'NO', true, 'no usable code means no restriction'],
            'not an array' =>
                [['supported_buyer_countries' => 'NO'], 'GB', true, 'unusable shape means no restriction'],
            'no record at all' =>
                [null, 'GB', true, 'an unresolvable record must not withdraw the method'],
            'country present' =>
                [['supported_buyer_countries' => ['NO', 'GB']], 'GB', true, 'listed country allowed'],
            'country absent' =>
                [['supported_buyer_countries' => ['NO', 'GB']], 'DE', false, 'unlisted country refused'],
            'lowercase buyer country' =>
                [['supported_buyer_countries' => ['NO', 'GB']], 'gb', true, 'buyer code compared case-insensitively'],
            'lowercase record code' =>
                [['supported_buyer_countries' => ['no', 'gb']], 'GB', true, 'record code compared case-insensitively'],
            'padded record code' =>
                [['supported_buyer_countries' => [' GB ']], 'GB', true, 'surrounding whitespace ignored'],
            'empty buyer country' =>
                [['supported_buyer_countries' => ['NO']], '', false, 'no country cannot match a restriction'],
        ];
    }

    public function testTheStoreIdIsPassedThroughToTheRecordLookup(): void
    {
        // Multi-store installs can hold a different API key per store, so the
        // record must be resolved in the caller's scope.
        $recordProvider = $this->createMock(RecordProvider::class);
        $recordProvider->expects($this->once())
            ->method('getRecord')
            ->with(7)
            ->willReturn(['supported_buyer_countries' => ['NO']]);

        $provider = new SupportedCountriesProvider($recordProvider);

        $this->assertTrue($provider->isAllowed('NO', 7));
    }
}
