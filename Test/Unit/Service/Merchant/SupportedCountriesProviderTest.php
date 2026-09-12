<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Service\Merchant;

use PHPUnit\Framework\TestCase;
use Two\Gateway\Service\Merchant\RecordProvider;
use Two\Gateway\Service\Merchant\SupportedCountriesProvider;

/**
 * TWO-40: the allowlist read is tri-state, keyed on whether the record
 * carries the field at all. No field means the API is not enforcing a
 * restriction, so every country is allowed; a field that is present but
 * carries no usable code is a restriction that admits nobody. An
 * unresolvable record is the absent case, not the empty one.
 */
class SupportedCountriesProviderTest extends TestCase
{
    /**
     * @param array<string,mixed>|null $record
     * @param list<string>|null $expectedCountries
     * @dataProvider allowlistCases
     */
    public function testCountryIsAllowedOnlyWhenTheRecordSaysSo(
        ?array $record,
        string $country,
        bool $expected,
        string $expectedState,
        ?array $expectedCountries,
        string $case
    ): void {
        $recordProvider = $this->createMock(RecordProvider::class);
        $recordProvider->method('getRecord')->willReturn($record);

        $provider = new SupportedCountriesProvider($recordProvider);

        $this->assertSame($expected, $provider->isAllowed($country), $case);
        $this->assertSame($expectedState, $provider->getState(), $case);
        $this->assertSame($expectedCountries, $provider->getAllowedCountries(), $case);
    }

    /**
     * @return array<string, array{
     *     0: array<string,mixed>|null, 1: string, 2: bool, 3: string, 4: list<string>|null, 5: string
     * }>
     */
    public static function allowlistCases(): array
    {
        $unrestricted = SupportedCountriesProvider::STATE_UNRESTRICTED;
        $allowlist = SupportedCountriesProvider::STATE_ALLOWLIST;
        $empty = SupportedCountriesProvider::STATE_EMPTY;
        $malformed = SupportedCountriesProvider::STATE_MALFORMED;

        return [
            'field absent' =>
                [['min_order_amount' => 100], 'NO', true, $unrestricted, null,
                    'no field means no restriction'],
            'field null' =>
                [['supported_buyer_countries' => null], 'NO', false, $empty, [],
                    'a present null restricts every country'],
            'empty array' =>
                [['supported_buyer_countries' => []], 'NO', false, $empty, [],
                    'a present empty list restricts every country'],
            'blank entries only' =>
                [['supported_buyer_countries' => ['', '  ']], 'NO', false, $empty, [],
                    'a list with no usable code admits nobody'],
            'not an array' =>
                [['supported_buyer_countries' => 'NO'], 'GB', false, $malformed, [],
                    'a scalar is refused, not read as a single code'],
            'json object rather than list' =>
                [['supported_buyer_countries' => ['primary' => 'GB']], 'GB', false, $malformed, [],
                    'a keyed map is not an allowlist'],
            'no record at all' =>
                [null, 'GB', true, $unrestricted, null,
                    'an unresolvable record must not withdraw the method'],
            'country present' =>
                [['supported_buyer_countries' => ['NO', 'GB']], 'GB', true, $allowlist, ['NO', 'GB'],
                    'listed country allowed'],
            'country absent' =>
                [['supported_buyer_countries' => ['NO', 'GB']], 'DE', false, $allowlist, ['NO', 'GB'],
                    'unlisted country refused'],
            'lowercase buyer country' =>
                [['supported_buyer_countries' => ['NO', 'GB']], 'gb', true, $allowlist, ['NO', 'GB'],
                    'buyer code compared case-insensitively'],
            'lowercase record code' =>
                [['supported_buyer_countries' => ['no', 'gb']], 'GB', true, $allowlist, ['NO', 'GB'],
                    'record code compared case-insensitively'],
            'padded record code' =>
                [['supported_buyer_countries' => [' GB ']], 'GB', true, $allowlist, ['GB'],
                    'surrounding whitespace ignored'],
            'padded buyer country' =>
                [['supported_buyer_countries' => ['GB']], ' gb ', true, $allowlist, ['GB'],
                    'buyer code trimmed before comparison'],
            'unusable codes dropped from a usable list' =>
                [['supported_buyer_countries' => ['GB', '', 5]], 'GB', true, $allowlist, ['GB'],
                    'one usable code still makes an allowlist'],
            'empty buyer country while restricted' =>
                [['supported_buyer_countries' => ['NO']], '', false, $allowlist, ['NO'],
                    'an unresolvable country cannot satisfy a restriction'],
            'empty buyer country while unrestricted' =>
                [['min_order_amount' => 100], '', true, $unrestricted, null,
                    'an unresolvable country is only a problem when the merchant restricts'],
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
