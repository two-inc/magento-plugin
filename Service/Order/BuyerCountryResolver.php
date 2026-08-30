<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Service\Order;

use Magento\Quote\Api\Data\CartInterface;
use Magento\Quote\Model\Quote;

/**
 * Buyer country for a quote: billing, then shipping, then the store default.
 *
 * Empty string when none resolves — "cannot judge", never a country.
 */
class BuyerCountryResolver
{
    public function resolve(?CartInterface $quote): string
    {
        if (!$quote instanceof Quote) {
            return '';
        }
        $billing = $quote->getBillingAddress();
        if ($billing && $billing->getCountryId()) {
            return (string)$billing->getCountryId();
        }
        $shipping = $quote->getShippingAddress();
        if ($shipping && $shipping->getCountryId()) {
            return (string)$shipping->getCountryId();
        }
        $store = $quote->getStore();
        return $store !== null ? (string)$store->getConfig('general/country/default') : '';
    }
}
