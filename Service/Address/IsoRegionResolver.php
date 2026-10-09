<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Service\Address;

/**
 * Finds the store's own region for an ISO 3166-2 subdivision code ("ES-M",
 * "IT-RM"), which is how the company lookup answers an address region and
 * which no region select is labelled with (TWO-26263).
 *
 * Only a code for the address's own country is considered. It is matched,
 * case-insensitively, against the store's region codes: first as the whole
 * code, then by its suffix, numeric suffixes compared as numbers so "FR-01"
 * meets a store that codes Ain as "1". Countries whose released core codes
 * are not ISO suffixes fall back to a table. Anything else matches nothing.
 */
class IsoRegionResolver
{
    private const ISO_CODE = '/^([A-Z]{2})-([A-Z0-9]{1,3})$/';

    /**
     * ISO suffix => the core region code(s) for it, for countries whose
     * released core codes are not ISO suffixes. Taken from Magento's own
     * UpdateRegionCodesFor<Country>V1 data patches, which recode these
     * countries to ISO in a later release; a store already on those codes is
     * matched before this table is reached. Where that later recoding gave a
     * Spanish province its autonomous community's code instead, that code is
     * listed beside the earlier one.
     *
     * @var array<string, array<string, string|string[]>>
     */
    private const LEGACY_CODES = [
        'AT' => [
            '1' => 'BL', '2' => 'KN', '3' => 'NO', '4' => 'OO', '5' => 'SB',
            '6' => 'ST', '7' => 'TI', '8' => 'VB', '9' => 'WI',
        ],
        'DE' => [
            'BB' => 'BRG', 'BE' => 'BER', 'BW' => 'BAW', 'BY' => 'BAY',
            'HB' => 'BRE', 'HE' => 'HES', 'HH' => 'HAM', 'MV' => 'MEC',
            'NI' => 'NDS', 'NW' => 'NRW', 'RP' => 'RHE', 'SH' => 'SCN',
            'SL' => 'SAR', 'SN' => 'SAS', 'ST' => 'SAC', 'TH' => 'THE',
        ],
        'ES' => [
            'A' => 'Alicante', 'AB' => 'Albacete', 'AL' => 'Almeria',
            'AV' => 'Avila', 'B' => 'Barcelona', 'BA' => 'Badajoz',
            'BI' => 'Vizcaya', 'BU' => 'Burgos',
            // Core's code spells this with a Cyrillic "с" (U+0441), not a Latin "c".
            'C' => "A Coru\u{0441}a",
            'CA' => 'Cadiz', 'CC' => 'Caceres', 'CE' => 'Ceuta',
            'CO' => 'Cordoba', 'CR' => 'Ciudad Real', 'CS' => 'Castellon',
            'CU' => 'Cuenca', 'GC' => 'Las Palmas', 'GI' => 'Girona',
            'GR' => 'Granada', 'GU' => 'Guadalajara', 'H' => 'Huelva',
            'HU' => 'Huesca', 'J' => 'Jaen', 'L' => 'Lleida', 'LE' => 'Leon',
            'LO' => 'La Rioja', 'LU' => 'Lugo', 'M' => ['Madrid', 'ES-MD'],
            'MA' => 'Malaga', 'ML' => 'Melilla', 'MU' => 'Murcia',
            'NA' => 'Navarra', 'O' => ['Asturias', 'ES-AS'], 'OR' => 'Ourense',
            'P' => 'Palencia', 'PM' => ['Baleares', 'ES-IB'], 'PO' => 'Pontevedra',
            'S' => ['Cantabria', 'ES-CB'], 'SA' => 'Salamanca', 'SE' => 'Sevilla',
            'SG' => 'Segovia', 'SO' => 'Soria', 'SS' => 'Guipuzcoa',
            'T' => 'Tarragona', 'TE' => 'Teruel', 'TF' => 'Santa Cruz de Tenerife',
            'TO' => 'Toledo', 'V' => ['Valencia', 'ES-VC'], 'VA' => 'Valladolid',
            'VI' => 'Alava', 'Z' => 'Zaragoza', 'ZA' => 'Zamora',
        ],
        'FI' => [
            '01' => 'Ahvenanmaa', '02' => 'Etelä-Karjala', '03' => 'Etelä-Pohjanmaa',
            '04' => 'Etelä-Savo', '05' => 'Kainuu', '06' => 'Kanta-Häme',
            '07' => 'Keski-Pohjanmaa', '08' => 'Keski-Suomi', '09' => 'Kymenlaakso',
            '10' => 'Lappi', '11' => 'Pirkanmaa', '12' => 'Pohjanmaa',
            '13' => 'Pohjois-Karjala', '14' => 'Pohjois-Pohjanmaa', '15' => 'Pohjois-Savo',
            '16' => 'Päijät-Häme', '17' => 'Satakunta', '18' => 'Uusimaa',
            '19' => 'Varsinais-Suomi',
        ],
    ];

    public function __construct(
        private readonly RegionDirectory $regionDirectory
    ) {
    }

    /**
     * @param string $countryId the address's own country, ISO 3166-1 alpha-2
     * @param mixed $region the region as the lookup answered it
     * @return array{region_id: int, region_code: string}|null null when nothing matches
     */
    public function resolve(string $countryId, $region): ?array
    {
        $countryId = strtoupper(trim($countryId));
        if (!is_string($region)
            || preg_match(self::ISO_CODE, strtoupper(trim($region)), $parts) !== 1
            || $parts[1] !== $countryId
        ) {
            return null;
        }
        [$code, , $suffix] = $parts;

        $regions = $this->regionDirectory->forCountry($countryId);
        $wanted = array_map(
            'mb_strtoupper',
            array_merge([$code, $suffix], (array)(self::LEGACY_CODES[$countryId][$suffix] ?? []))
        );
        foreach ($wanted as $candidate) {
            foreach ($regions as $regionId => $regionCode) {
                if ($this->sameCode($candidate, mb_strtoupper($regionCode))) {
                    return ['region_id' => $regionId, 'region_code' => $regionCode];
                }
            }
        }

        return null;
    }

    /**
     * Adds `region_id` and `region_code` to a relayed address whose region
     * resolves, leaving every field it already carried untouched.
     *
     * @param mixed $address one entry of a company record's `addresses`
     * @return mixed
     */
    public function enrich($address)
    {
        if (!is_array($address) || !is_string($address['country'] ?? null)) {
            return $address;
        }
        $match = $this->resolve($address['country'], $address['region'] ?? null);

        return $match === null ? $address : $address + $match;
    }

    private function sameCode(string $wanted, string $regionCode): bool
    {
        if ($wanted === $regionCode) {
            return true;
        }

        return ctype_digit($wanted) && ctype_digit($regionCode) && (int)$wanted === (int)$regionCode;
    }
}
