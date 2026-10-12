<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Model\Config;

/**
 * What "Tax codes for 0% lines" shows for the scope being edited (TWO-26153).
 * Admin only: nothing at order time reads it. TaxCodeSectionResolver gathers
 * the facts; this decides, first match wins:
 *
 *   no API key                    hidden
 *   no merchant record            profile_error  why it could not be read (rejected when the key was refused)
 *   no answer from the code list  list_error     why it could not be read
 *   codes mandatory at 0%         rows           the mapping table
 *   otherwise                     notice         the saved mapping's standing, else hidden
 *
 * On top of every view but hidden: an unreadable saved mapping (N3, with
 * Clear), and, at the default scope, websites whose API key or environment
 * differs from it (SCOPE).
 */
class TaxCodeSection
{
    public const VIEW_HIDDEN = 'hidden';
    public const VIEW_PROFILE_ERROR = 'profile_error';
    public const VIEW_LIST_ERROR = 'list_error';
    public const VIEW_ROWS = 'rows';
    public const VIEW_NOTICE = 'notice';

    /** The saved mapping cannot be read, so checkout withholds the payment method. */
    public const NOTICE_UNREADABLE = 'N3';
    /** Every saved code is listed at 0% for the country, which does not require them. */
    public const NOTICE_SAVED_VALID = 'N1';
    /** A saved code is unlisted, or not at 0%, for the country. */
    public const NOTICE_SAVED_INVALID = 'N2';
    /** A website below the default scope uses another API key or environment. */
    public const NOTICE_SCOPE = 'SCOPE';

    /**
     * @param array{
     *     has_key: bool,
     *     readable: bool,
     *     entries: array<string, string>,
     *     country: string,
     *     rejected: bool,
     *     profile_http: int,
     *     list: array{codes: array, mandatory: bool}|null,
     *     list_http: int,
     *     scope_differs: bool
     * } $facts country '' when the merchant record could not be read
     * @return array{view: string, notices: string[], clear: bool, country: string, codes: array,
     *     rejected: bool, http: int}
     */
    public static function state(array $facts): array
    {
        $state = ['view' => self::VIEW_HIDDEN, 'notices' => [], 'clear' => false, 'country' => '', 'codes' => [],
            'rejected' => false, 'http' => 0];
        if (empty($facts['has_key'])) {
            return $state;
        }
        if (empty($facts['readable'])) {
            $state['notices'][] = self::NOTICE_UNREADABLE;
            $state['clear'] = true;
        }
        if (!empty($facts['scope_differs'])) {
            $state['notices'][] = self::NOTICE_SCOPE;
        }
        $country = (string)($facts['country'] ?? '');
        if ($country === '') {
            $state['view'] = self::VIEW_PROFILE_ERROR;
            $state['rejected'] = !empty($facts['rejected']);
            $state['http'] = (int)($facts['profile_http'] ?? 0);
            return $state;
        }
        $state['country'] = $country;
        $list = $facts['list'] ?? null;
        if ($list === null) {
            $state['view'] = self::VIEW_LIST_ERROR;
            $state['http'] = (int)($facts['list_http'] ?? 0);
            return $state;
        }
        $state['codes'] = $list['codes'];
        if (!empty($list['mandatory'])) {
            $state['view'] = self::VIEW_ROWS;
            return $state;
        }
        $entries = $facts['entries'] ?? [];
        if (!empty($facts['readable']) && $entries !== []) {
            $state['notices'][] = self::allZeroAndListed($entries, $state['codes'])
                ? self::NOTICE_SAVED_VALID : self::NOTICE_SAVED_INVALID;
            $state['clear'] = true;
        }
        $state['view'] = $state['notices'] === [] ? self::VIEW_HIDDEN : self::VIEW_NOTICE;

        return $state;
    }

    /**
     * Why the section cannot show its rows, or null when nothing failed. The
     * form shows it, and the save gives it when an edit could not be saved.
     *
     * @param array $state TaxCodeSection::state()
     */
    public static function error(array $state, string $brand, string $country): ?string
    {
        $http = $state['http'] > 0 ? (string)$state['http'] : '-';
        if ($state['view'] === TaxCodeSection::VIEW_PROFILE_ERROR) {
            return $state['rejected']
                ? (string)__('%1 rejected the stored API key; check API key and Environment.', $brand)
                : (string)__(
                    'Could not read your merchant details from %1 (HTTP %2); try again in a few minutes.',
                    $brand,
                    $http
                );
        }
        if ($state['view'] === TaxCodeSection::VIEW_LIST_ERROR) {
            return (string)__(
                'Could not check whether %1 requires tax codes for your country (%2, HTTP %3); try again in a few minutes.',
                $brand,
                $country,
                $http
            );
        }

        return null;
    }

    /**
     * Whether the metadata of GET /v1/tax_codes/{country} makes a code
     * mandatory on a 0% line: mandatory codes are enabled and one of the
     * mandatory rates is numerically zero.
     *
     * @param mixed $metadata
     */
    public static function isMandatoryAtZero($metadata): bool
    {
        if (!is_array($metadata) || empty($metadata['mandatory_tax_codes_enabled'])
            || !is_array($metadata['mandatory_tax_rates'] ?? null)
        ) {
            return false;
        }
        foreach ($metadata['mandatory_tax_rates'] as $rate) {
            if (is_numeric($rate) && (float)$rate === 0.0) {
                return true;
            }
        }

        return false;
    }

    /**
     * The codes a 0% line may carry: the listed codes at rate 0. The API
     * refuses any other code on a 0% line.
     *
     * @param array<int, array{code: string, name: string, rate: string}> $codes
     * @return array<int, array{code: string, name: string, rate: string}>
     */
    public static function zeroRated(array $codes): array
    {
        return array_values(array_filter($codes, static function ($code): bool {
            return is_array($code) && is_numeric($code['rate'] ?? null) && (float)$code['rate'] === 0.0;
        }));
    }

    /**
     * @param array<string, string> $entries
     */
    private static function allZeroAndListed(array $entries, array $codes): bool
    {
        $zero = array_column(self::zeroRated($codes), 'code');
        foreach ($entries as $code) {
            if (!in_array($code, $zero, true)) {
                return false;
            }
        }

        return true;
    }
}
