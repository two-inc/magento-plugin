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
     * ISO suffix => the core region code(s) for it, wherever a released core
     * code is neither the ISO code nor its suffix. Taken from Magento's own
     * UpdateRegionCodesFor<Country>V1 data patches, which recode these regions
     * to ISO in a later release; a store already on those codes is matched
     * before this table is reached. That recoding gives the Balearic Islands
     * province its autonomous community's code and adds no row for the
     * province, so that code is listed beside the earlier one.
     *
     * @var array<string, array<string, string|string[]>>
     */
    private const LEGACY_CODES = [
        'AT' => [
            '1' => 'BL', '2' => 'KN', '3' => 'NO', '4' => 'OO', '5' => 'SB',
            '6' => 'ST', '7' => 'TI', '8' => 'VB', '9' => 'WI',
        ],
        'CO' => ['HUI' => 'CO-HUL'],
        'CR' => [
            'A' => 'CR-AL', 'C' => 'CR-CA', 'G' => 'CR-GU', 'H' => 'CR-HE', 'L' => 'CR-LI', 'P' => 'CR-PU',
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
            'LO' => 'La Rioja', 'LU' => 'Lugo', 'M' => 'Madrid',
            'MA' => 'Malaga', 'ML' => 'Melilla', 'MU' => 'Murcia',
            'NA' => 'Navarra', 'O' => 'Asturias', 'OR' => 'Ourense',
            'P' => 'Palencia', 'PM' => ['Baleares', 'ES-IB'], 'PO' => 'Pontevedra',
            'S' => 'Cantabria', 'SA' => 'Salamanca', 'SE' => 'Sevilla',
            'SG' => 'Segovia', 'SO' => 'Soria', 'SS' => 'Guipuzcoa',
            'T' => 'Tarragona', 'TE' => 'Teruel', 'TF' => 'Santa Cruz de Tenerife',
            'TO' => 'Toledo', 'V' => 'Valencia', 'VA' => 'Valladolid',
            'VI' => 'Alava', 'Z' => 'Zaragoza', 'ZA' => 'Zamora',
        ],
        'EE' => [
            '45' => 'EE-44', '50' => 'EE-49', '52' => 'EE-51', '56' => 'EE-57', '60' => 'EE-59',
            '64' => 'EE-65', '68' => 'EE-67', '71' => 'EE-70', '79' => 'EE-78', '81' => 'EE-82',
            '87' => 'EE-86',
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
        // 01-09 already meet core's "1"-"9" as numbers; Paris is the one left.
        'FR' => ['75C' => '75'],
        'IN' => ['CG' => 'CT', 'DH' => 'DN', 'OD' => 'OR', 'TS' => 'TG', 'UK' => 'UT'],
        'IS' => [
            '1' => 'IS-01', '2' => 'IS-02', '3' => 'IS-03', '4' => 'IS-04',
            '5' => 'IS-05', '6' => 'IS-06', '7' => 'IS-07', '8' => 'IS-08',
        ],
        'LV' => [
            '002' => 'LV-AI', '007' => 'LV-AL', '011' => 'Ādažu novads', '015' => 'LV-BL',
            '016' => 'LV-BU', '022' => 'LV-CE', '026' => 'LV-DO', '033' => 'LV-GU',
            '041' => 'LV-JL', '042' => 'LV-JK', '047' => 'LV-KR', '050' => 'LV-KU',
            '052' => 'Ķekavas novads', '054' => 'LV-LM', '056' => 'Līvānu novads', '058' => 'LV-LU',
            '059' => 'LV-MA', '062' => 'Mārupes novads', '067' => 'LV-OG', '068' => 'Olaines novads',
            '073' => 'LV-PR', '077' => 'LV-RE', '080' => 'Ropažu novads', '087' => 'Salaspils novads',
            '088' => 'LV-SA', '089' => 'Saulkrastu novads', '091' => 'Siguldas novads',
            '094' => 'Smiltenes novads', '097' => 'LV-TA', '099' => 'LV-TU', '101' => 'LV-VK',
            '102' => 'Varakļānu novads', '106' => 'LV-VE', '113' => 'LV-VM',
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
