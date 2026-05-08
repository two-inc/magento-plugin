<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace ABN\Gateway\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

/**
 * Copies surcharge fields from the quote's address onto the order at submit
 * time, before payment placement and the final repository->save().
 *
 * Why not sales_convert_quote_to_order? Two interface filters strip our fields:
 *   1. ToOrder::convert uses populateWithArray($order, ..., OrderInterface::class)
 *      which drops non-interface fields.
 *   2. QuoteManagement::submitQuote then constructs a SECOND order object via
 *      orderFactory->create() and merges the ToOrder result into it via
 *      mergeDataObjects(OrderInterface::class, $outer, $inner) — re-applying
 *      the same interface filter. Setting fields on the inner order (i.e. on
 *      the sales_convert_quote_to_order event) is wasted work — they never
 *      reach the order that gets saved.
 *
 * sales_model_service_quote_submit_before fires AFTER that merge, with the
 * outer (about-to-be-saved) order. Setting data here survives to persistence.
 *
 * The collector at Model/Total/Surcharge.php deliberately does not mirror onto
 * $quote (speculative recollect would clobber a previously-good value), so we
 * read from the chosen address. Running-total fields (invoiced/refunded) are
 * not copied — they start at zero on a new order.
 */
class QuoteToOrderSurcharge implements ObserverInterface
{
    private const FIELDS = [
        'two_surcharge_amount',
        'base_two_surcharge_amount',
        'two_surcharge_tax_amount',
        'base_two_surcharge_tax_amount',
        'two_surcharge_description',
        'two_surcharge_tax_rate',
    ];

    public function execute(Observer $observer): void
    {
        $event = $observer->getEvent();
        $order = $event->getOrder();
        $quote = $event->getQuote();

        if (!$order || !$quote) {
            return;
        }

        $address = $quote->isVirtual() ? $quote->getBillingAddress() : $quote->getShippingAddress();
        if (!$address) {
            return;
        }

        $amount = $address->getData('two_surcharge_amount');
        if ($amount === null || (float)$amount <= 0) {
            return;
        }

        foreach (self::FIELDS as $field) {
            $value = $address->getData($field);
            if ($value !== null) {
                $order->setData($field, $value);
            }
        }
    }
}
