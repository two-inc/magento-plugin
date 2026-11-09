<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Service\Address;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Service\Address\PostcodeFormat;

/**
 * TWO-27158: a registry postcode is written the way its country writes it.
 */
class PostcodeFormatTest extends TestCase
{
    /**
     * @return array<string, array{string, string, string}>
     */
    public static function fitCases(): array
    {
        return [
            'NL gains its space' => ['1234AB', 'NL', '1234 AB'],
            'NL keeps its letter case' => ['1234ab', 'nl', '1234 ab'],
            'NL already written right' => ['1234 AB', 'NL', '1234 AB'],
            'PL gains its hyphen' => ['00950', 'PL', '00-950'],
            'PT gains its hyphen' => ['1000001', 'PT', '1000-001'],
            'SE gains its space' => ['11455', 'SE', '114 55'],
            'CZ swaps a hyphen for its space' => ['110-00', 'CZ', '110 00'],
            'MT letters then digits' => ['VLT1117', 'MT', 'VLT 1117'],
            'country code is trimmed' => ['1234AB', ' NL ', '1234 AB'],
            'too short is left alone' => ['1234A', 'NL', '1234A'],
            'too long is left alone' => ['1234ABC', 'NL', '1234ABC'],
            'a letter in a digit slot is left alone' => ['12A4AB', 'NL', '12A4AB'],
            'a country with no format is left alone' => ['SW1A1AA', 'GB', 'SW1A1AA'],
            'no country is left alone' => ['1234AB', '', '1234AB'],
            'an empty postcode stays empty' => ['', 'NL', ''],
        ];
    }

    #[DataProvider('fitCases')]
    public function testFit(string $postcode, string $country, string $expected): void
    {
        $this->assertSame($expected, PostcodeFormat::fit($postcode, $country));
    }

    /**
     * @return array<string, array{mixed, string, mixed}>
     */
    public static function fitAddressCases(): array
    {
        return [
            'the address country decides' => [['postal_code' => '00950', 'country' => 'PL'], 'NL', ['postal_code' => '00-950', 'country' => 'PL']],
            'the company country stands in for a missing one' => [['postal_code' => '1234AB'], 'NL', ['postal_code' => '1234 AB']],
            'the company country stands in for an empty one' => [['postal_code' => '1234AB', 'country' => ''], 'NL', ['postal_code' => '1234 AB', 'country' => '']],
            'no country anywhere leaves it alone' => [['postal_code' => '1234AB'], '', ['postal_code' => '1234AB']],
            'an address with no postcode is unchanged' => [['city' => 'Amsterdam'], 'NL', ['city' => 'Amsterdam']],
            'a non-string postcode is unchanged' => [['postal_code' => 1234], 'NL', ['postal_code' => 1234]],
            'a non-array entry is unchanged' => ['oops', 'NL', 'oops'],
        ];
    }

    #[DataProvider('fitAddressCases')]
    public function testFitAddress($address, string $companyCountry, $expected): void
    {
        $this->assertSame($expected, PostcodeFormat::fitAddress($address, $companyCountry));
    }
}
