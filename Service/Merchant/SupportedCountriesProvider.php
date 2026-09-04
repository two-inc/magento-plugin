<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Service\Merchant;

/**
 * The merchant's buyer-country allowlist, projected out of the merchant record.
 *
 * TWO-40: an absent field means unrestricted; present-but-empty, present-but-null
 * and a present malformed value all restrict every country.
 */
class SupportedCountriesProvider
{
    private const FIELD = 'supported_buyer_countries';

    /** No allowlist field in the record: the API is not enforcing one. */
    public const STATE_UNRESTRICTED = 'unrestricted';

    /** A usable list of codes. */
    public const STATE_ALLOWLIST = 'allowlist';

    /** Present but null, or carrying no usable code. */
    public const STATE_EMPTY = 'empty';

    /** Present but not a list of codes. */
    public const STATE_MALFORMED = 'malformed';

    /**
     * @var RecordProvider
     */
    private $recordProvider;

    public function __construct(RecordProvider $recordProvider)
    {
        $this->recordProvider = $recordProvider;
    }

    public function isAllowed(string $country, ?int $storeId = null): bool
    {
        $allowed = $this->getAllowedCountries($storeId);
        if ($allowed === null) {
            return true;
        }
        return in_array(strtoupper(trim($country)), $allowed, true);
    }

    /**
     * @return list<string>|null null when the merchant restricts nothing; an
     *     empty list when it allows nothing.
     */
    public function getAllowedCountries(?int $storeId = null): ?array
    {
        return $this->resolve($storeId)['countries'];
    }

    /**
     * One of the STATE_* constants, for the rejection log.
     */
    public function getState(?int $storeId = null): string
    {
        return $this->resolve($storeId)['state'];
    }

    /**
     * @return array{state: string, countries: list<string>|null}
     */
    private function resolve(?int $storeId): array
    {
        $record = $this->recordProvider->getRecord($storeId);
        // An unresolvable record is not an empty allowlist — a fetch failure
        // must not withdraw the method.
        if ($record === null || !array_key_exists(self::FIELD, $record)) {
            return ['state' => self::STATE_UNRESTRICTED, 'countries' => null];
        }

        $codes = $record[self::FIELD];
        if ($codes === null) {
            return ['state' => self::STATE_EMPTY, 'countries' => []];
        }
        if (!is_array($codes) || !array_is_list($codes)) {
            return ['state' => self::STATE_MALFORMED, 'countries' => []];
        }

        $normalised = [];
        foreach ($codes as $code) {
            if (is_string($code) && trim($code) !== '') {
                $normalised[] = strtoupper(trim($code));
            }
        }

        return $normalised === []
            ? ['state' => self::STATE_EMPTY, 'countries' => []]
            : ['state' => self::STATE_ALLOWLIST, 'countries' => $normalised];
    }
}
