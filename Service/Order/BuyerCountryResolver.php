<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Service\Order;

use Magento\Quote\Api\Data\CartInterface;
use Magento\Quote\Model\Quote;
use Magento\Sales\Model\Order;

/**
 * Buyer country for a quote or a placed order: billing, then shipping, then
 * the store default.
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

        return $this->firstCountry(
            $quote->getBillingAddress(),
            $quote->getShippingAddress(),
            $quote->getStore()
        );
    }

    /**
     * An order is not a CartInterface, so it cannot go through resolve().
     */
    public function resolveFromOrder(Order $order): string
    {
        return $this->firstCountry(
            $order->getBillingAddress(),
            $order->getShippingAddress(),
            $order->getStore()
        );
    }

    /**
     * @param mixed $billing an address, null, or false for a virtual order
     * @param mixed $shipping an address, null, or false for a virtual order
     */
    private function firstCountry($billing, $shipping, ?object $store): string
    {
        foreach ([$billing, $shipping] as $address) {
            if ($address && $address->getCountryId()) {
                return (string)$address->getCountryId();
            }
        }

        return $store !== null ? (string)$store->getConfig('general/country/default') : '';
    }
}
