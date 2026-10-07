<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Service\Order;

use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address\ToOrder as QuoteAddressToOrder;
use Magento\Quote\Model\Quote\Address\ToOrderAddress as QuoteAddressToOrderAddress;
use Magento\Quote\Model\Quote\Item\ToOrderItem as QuoteItemToOrderItem;
use Magento\Sales\Model\Order;

/**
 * Composes the order-intent request from the quote, server-side, through the
 * create request's own line builder (TWO-26092). The browser supplies only
 * the buyer.
 */
class ComposeIntent
{
    /**
     * @var ComposeOrder
     */
    private $composeOrder;

    /**
     * @var QuoteAddressToOrder
     */
    private $quoteAddressToOrder;

    /**
     * @var QuoteAddressToOrderAddress
     */
    private $quoteAddressToOrderAddress;

    /**
     * @var QuoteItemToOrderItem
     */
    private $quoteItemToOrderItem;

    public function __construct(
        ComposeOrder $composeOrder,
        QuoteAddressToOrder $quoteAddressToOrder,
        QuoteAddressToOrderAddress $quoteAddressToOrderAddress,
        QuoteItemToOrderItem $quoteItemToOrderItem
    ) {
        $this->composeOrder = $composeOrder;
        $this->quoteAddressToOrder = $quoteAddressToOrder;
        $this->quoteAddressToOrderAddress = $quoteAddressToOrderAddress;
        $this->quoteItemToOrderItem = $quoteItemToOrderItem;
    }

    /**
     * @param Quote $quote
     * @param array $buyer
     * @return array
     * @throws LocalizedException
     */
    public function execute(Quote $quote, array $buyer): array
    {
        $order = $this->toOrder($quote);
        $grossTotal = (float)$order->getGrandTotal();
        $taxTotal = (float)$order->getTaxAmount();

        return [
            'gross_amount' => $this->composeOrder->roundAmt($grossTotal),
            'net_amount' => $this->composeOrder->roundAmt($grossTotal - $taxTotal),
            'tax_amount' => $this->composeOrder->roundAmt($taxTotal),
            'currency' => (string)$quote->getQuoteCurrencyCode(),
            'line_items' => $this->composeOrder->composeLineItems($order),
            'buyer' => $buyer,
        ];
    }

    /**
     * The quote as an unsaved order, converted the way core's
     * QuoteManagement::submitQuote() converts it at placement.
     *
     * @param Quote $quote
     * @return Order
     */
    public function toOrder(Quote $quote): Order
    {
        // Applied taxes are rebuilt only by a collect, and the shipping rate is read from them.
        $quote->setTotalsCollectedFlag(false);
        $quote->collectTotals();

        $address = $quote->isVirtual() ? $quote->getBillingAddress() : $quote->getShippingAddress();
        /** @var Order $order */
        $order = $this->quoteAddressToOrder->convert($address);

        if (!$quote->isVirtual()) {
            $order->setShippingAddress(
                $this->quoteAddressToOrderAddress->convert($quote->getShippingAddress(), ['address_type' => 'shipping'])
            );
            $order->setShippingMethod($quote->getShippingAddress()->getShippingMethod());
        }
        $order->setBillingAddress(
            $this->quoteAddressToOrderAddress->convert($quote->getBillingAddress(), ['address_type' => 'billing'])
        );

        $items = [];
        foreach ($quote->getAllItems() as $quoteItem) {
            $key = $quoteItem->getId() ?? spl_object_id($quoteItem);
            if (isset($items[$key])) {
                continue;
            }
            $parent = $quoteItem->getParentItem();
            $parentKey = $parent ? ($parent->getId() ?? spl_object_id($parent)) : null;
            if ($parent && !isset($items[$parentKey])) {
                $items[$parentKey] = $this->quoteItemToOrderItem->convert($parent, ['parent_item' => null]);
            }
            $items[$key] = $this->quoteItemToOrderItem->convert(
                $quoteItem,
                ['parent_item' => $parent ? $items[$parentKey] : null]
            );
        }
        $order->setItems(array_values($items));
        $order->setCustomerGroupId($quote->getCustomerGroupId());
        $order->setCustomerId($quote->getCustomerId());
        $order->setIsVirtual($quote->isVirtual() ? 1 : 0);

        return $order;
    }
}
