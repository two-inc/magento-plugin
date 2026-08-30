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
 * TWO-40: an absent, null or empty allowlist means EVERY country is allowed, never none.
 */
class SupportedCountriesProvider
{
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
        return in_array(strtoupper($country), $allowed, true);
    }

    /**
     * @return list<string>|null null when the merchant restricts nothing.
     */
    private function getAllowedCountries(?int $storeId): ?array
    {
        $record = $this->recordProvider->getRecord($storeId);
        if ($record === null) {
            return null;
        }
        $codes = $record['supported_buyer_countries'] ?? null;
        if (!is_array($codes)) {
            return null;
        }
        $normalised = [];
        foreach ($codes as $code) {
            if (is_string($code) && trim($code) !== '') {
                $normalised[] = strtoupper(trim($code));
            }
        }
        return $normalised === [] ? null : $normalised;
    }
}
