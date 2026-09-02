<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Service\Order;

use Magento\Tax\Model\Config as TaxConfig;

/**
 * Resolves whether a surcharge amount is presented net, gross, or both.
 *
 * Two tiers, mirroring Magento's own split: the cart tier covers the live
 * checkout and cart surfaces, the sales tier covers order/invoice/creditmemo
 * documents and emails.
 *
 * Keyed on `tax/cart_display/price` and `tax/sales_display/price` — the
 * store-wide "Display Prices" switches, which is what a merchant flips to put
 * gross values in front of the buyer. The narrower `subtotal` and `shipping`
 * keys govern one named row each and say nothing about a payment fee.
 *
 * Read through Magento\Tax\Model\Config, as core's own Subtotal and Shipping
 * totals blocks do — Tax\Helper\Data only delegates to it, and drags the
 * catalog and order-tax graphs into the collectTotals path to do so.
 *
 * Choosing net or gross never changes totals arithmetic: the surcharge tax is
 * already in the Tax line and the gross is already in the grand total, exactly
 * as core carries "Subtotal (Incl. Tax)" alongside a separate Tax row.
 */
class SurchargeDisplay
{
    public const EXCL = 'excl';
    public const INCL = 'incl';
    public const BOTH = 'both';

    private TaxConfig $taxConfig;

    public function __construct(TaxConfig $taxConfig)
    {
        $this->taxConfig = $taxConfig;
    }

    /**
     * @param null|int|string|\Magento\Store\Model\Store $store
     */
    public function forCart($store = null): string
    {
        if ($this->taxConfig->displayCartPricesBoth($store)) {
            return self::BOTH;
        }
        return $this->taxConfig->displayCartPricesInclTax($store) ? self::INCL : self::EXCL;
    }

    /**
     * @param null|int|string|\Magento\Store\Model\Store $store
     */
    public function forSales($store = null): string
    {
        if ($this->taxConfig->displaySalesPricesBoth($store)) {
            return self::BOTH;
        }
        return $this->taxConfig->displaySalesPricesInclTax($store) ? self::INCL : self::EXCL;
    }

    /**
     * The single amount to render for a mode, gross for BOTH — callers that
     * can show two rows read the mode directly instead.
     */
    public function pick(string $mode, float $net, float $tax): float
    {
        return $mode === self::EXCL ? $net : $net + $tax;
    }
}
