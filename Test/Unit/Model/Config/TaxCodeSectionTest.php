<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Model\Config;

use PHPUnit\Framework\TestCase;
use Two\Gateway\Model\Config\TaxCodeSection;

/**
 * TWO-26153: what "Tax codes for 0% lines" shows, decided from the facts alone.
 */
class TaxCodeSectionTest extends TestCase
{
    private const ES = [
        ['code' => 'ES_IVA_EXPORT', 'name' => 'Exportación', 'rate' => '0'],
        ['code' => 'ES_IVA_STANDARD', 'name' => 'IVA general', 'rate' => '0.21'],
    ];

    private const GB = [
        ['code' => 'GB_VAT_ZERO', 'name' => 'Zero', 'rate' => '0'],
        ['code' => 'GB_VAT_REVERSE_CHARGE', 'name' => 'Reverse charge', 'rate' => '0.0'],
        ['code' => 'GB_VAT_STANDARD', 'name' => 'Standard', 'rate' => '0.2'],
    ];

    /**
     * @dataProvider cases
     * @param array<string, mixed> $facts overrides of a key-holding ES merchant with nothing saved
     * @param array<string, mixed> $expected the state's keys that matter for the case
     */
    public function testState(array $facts, array $expected, string $description): void
    {
        $state = TaxCodeSection::state($facts + [
            'has_key' => true,
            'readable' => true,
            'entries' => [],
            'country' => 'ES',
            'rejected' => false,
            'profile_http' => 0,
            'list' => ['codes' => self::ES, 'mandatory' => true],
            'list_http' => 0,
            'scope_differs' => false,
        ]);

        $this->assertSame($expected, array_intersect_key($state, $expected), $description);
    }

    /**
     * @return array<int, array{0: array, 1: array, 2: string}>
     */
    public static function cases(): array
    {
        $gb = ['country' => 'GB', 'list' => ['codes' => self::GB, 'mandatory' => false]];
        $hidden = ['view' => 'hidden', 'notices' => [], 'clear' => false];

        return [
            [[], ['view' => 'rows', 'notices' => [], 'clear' => false, 'country' => 'ES'], 'ES, mandatory: the rows'],
            [$gb, $hidden, 'GB, nothing saved: nothing'],
            [$gb + ['entries' => ['2|none' => 'ES_IVA_EXPORT']], ['view' => 'notice', 'notices' => ['N2'], 'clear' => true], 'GB with an ES code: N2 and Clear'],
            [$gb + ['entries' => ['2|none' => 'GB_VAT_ZERO', '2|exempt' => 'GB_VAT_REVERSE_CHARGE']], ['view' => 'notice', 'notices' => ['N1'], 'clear' => true], 'GB with listed 0% codes: N1 and Clear'],
            [$gb + ['entries' => ['2|none' => 'GB_VAT_STANDARD']], ['view' => 'notice', 'notices' => ['N2'], 'clear' => true], 'GB with a 20% code: N2 and Clear'],
            [$gb + ['readable' => false], ['view' => 'notice', 'notices' => ['N3'], 'clear' => true], 'GB, unreadable map: N3 and Clear'],
            [['readable' => false], ['view' => 'rows', 'notices' => ['N3'], 'clear' => true], 'ES, unreadable map: N3 above the rows'],
            [['has_key' => false, 'readable' => false], $hidden, 'no key wins over an unreadable map'],
            [['country' => 'IT', 'list' => ['codes' => [], 'mandatory' => false]], $hidden, 'no registry: hidden'],
            [['country' => 'IT', 'list' => ['codes' => [], 'mandatory' => false], 'entries' => ['2|none' => 'IT_X']], ['view' => 'notice', 'notices' => ['N2']], 'no registry with a saved code: N2'],
            [['list' => null, 'list_http' => 503], ['view' => 'list_error', 'http' => 503], 'list outage, nothing cached: E2'],
            [['country' => '', 'profile_http' => 503], ['view' => 'profile_error', 'rejected' => false, 'http' => 503], 'merchant record unreadable: E1'],
            [['country' => '', 'rejected' => true, 'profile_http' => 401], ['view' => 'profile_error', 'rejected' => true], 'key rejected: E1b'],
            [['country' => '', 'readable' => false], ['view' => 'profile_error', 'notices' => ['N3'], 'clear' => true], 'E1 still offers Clear for an unreadable map'],
            [['scope_differs' => true], ['view' => 'rows', 'notices' => ['SCOPE']], 'a website with another key: scope notice'],
            [$gb + ['scope_differs' => true], ['view' => 'notice', 'notices' => ['SCOPE'], 'clear' => false], 'the scope notice alone shows the section, with no Clear'],
        ];
    }

    /**
     * @dataProvider metadata
     * @param mixed $metadata
     */
    public function testMandatoryMeansEnabledAtAZeroRate($metadata, bool $expected, string $description): void
    {
        $this->assertSame($expected, TaxCodeSection::isMandatoryAtZero($metadata), $description);
    }

    /**
     * @return array<int, array{0: mixed, 1: bool, 2: string}>
     */
    public static function metadata(): array
    {
        return [
            [['mandatory_tax_codes_enabled' => true, 'mandatory_tax_rates' => ['0']], true, 'enabled at "0"'],
            [['mandatory_tax_codes_enabled' => true, 'mandatory_tax_rates' => ['0.00']], true, 'enabled at "0.00"'],
            [['mandatory_tax_codes_enabled' => true, 'mandatory_tax_rates' => ['0.21']], false, 'enabled at 21% only'],
            [['mandatory_tax_codes_enabled' => false, 'mandatory_tax_rates' => ['0']], false, 'not enabled'],
            [['mandatory_tax_codes_enabled' => true, 'mandatory_tax_rates' => ['x']], false, 'a rate that is not a number'],
            [null, false, 'no metadata'],
        ];
    }

    public function testOnlyCodesAtRateZeroAreOfferedForZeroLines(): void
    {
        $this->assertSame(['GB_VAT_ZERO', 'GB_VAT_REVERSE_CHARGE'], array_column(TaxCodeSection::zeroRated(self::GB), 'code'));
    }

    /**
     * @dataProvider errors
     */
    public function testErrorSaysWhyTheRowsAreNotShown(array $state, ?string $expected, string $description): void
    {
        $this->assertSame($expected, TaxCodeSection::error($state + ['rejected' => false, 'http' => 0], 'Two', 'Spain'), $description);
    }

    /**
     * @return array<int, array{0: array, 1: string|null, 2: string}>
     */
    public static function errors(): array
    {
        return [
            [['view' => 'profile_error', 'http' => 503], 'Could not read your merchant details from Two (HTTP 503); try again in a few minutes.', 'E1'],
            [['view' => 'profile_error'], 'Could not read your merchant details from Two (HTTP -); try again in a few minutes.', 'E1 with no status'],
            [['view' => 'profile_error', 'rejected' => true, 'http' => 401], 'Two rejected the stored API key; check API key and Environment.', 'E1b'],
            [['view' => 'list_error', 'http' => 502], 'Could not check whether Two requires tax codes for your country (Spain, HTTP 502); try again in a few minutes.', 'E2'],
            [['view' => 'rows'], null, 'nothing failed'],
        ];
    }
}
