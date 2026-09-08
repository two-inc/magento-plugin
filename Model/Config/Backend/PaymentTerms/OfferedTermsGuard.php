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

    public function offered(?int $storeId): array
    {
        return array_map('intval', $this->settingsProvider->getAvailableTerms($storeId));
    }

    public function assertOffered(array $days, ?int $storeId): void
    {
        $offered = $this->offered($storeId);
        // No terms at all means an unresolvable record — unknown, not "none offered".
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
