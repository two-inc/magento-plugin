<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Model\Config\Backend\PaymentTerms;

use Magento\Framework\Exception\LocalizedException;
use Two\Gateway\Service\Merchant\SettingsProvider;

/**
 * Refuses a payment term the merchant record does not offer, shared by the admin fields (ABN-493).
 */
class OfferedTermsGuard
{
    private $settingsProvider;

    public function __construct(SettingsProvider $settingsProvider)
    {
        $this->settingsProvider = $settingsProvider;
    }

    public function offered(?int $storeId, ?string $scope = null): array
    {
        return array_map('intval', $this->settingsProvider->getAvailableTerms($storeId, $scope));
    }

    public function assertOffered(array $days, ?int $storeId, ?string $scope = null): void
    {
        $offered = $this->offered($storeId, $scope);
        // Refusing the save would lock the merchant out of correcting the API key that
        // resolves the record; the buyer path fails closed instead (ABN-493).
        if ($offered === []) {
            return;
        }

        $rejected = array_values(array_diff(array_unique($days), $offered));
        if ($rejected === []) {
            return;
        }

        throw new LocalizedException(__(
            'Payment terms you are not able to offer: %1 days. Choose from: %2 days.',
            implode(', ', $rejected),
            implode(', ', $offered)
        ));
    }
}
