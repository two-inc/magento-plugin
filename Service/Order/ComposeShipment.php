<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace ABN\Gateway\Service\Order;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Api\Data\OrderItemInterface;
use Magento\Sales\Api\Data\ShipmentInterface;
use Magento\Sales\Api\Data\ShipmentItemInterface;
use Magento\Sales\Model\Order;
use ABN\Gateway\Service\Order as OrderService;

/**
 * Compose Shipment Service
 */
class ComposeShipment extends OrderService
{
    /**
     * Compose request body for two ship order
     *
     * @param Order\Shipment $shipment
     * @param Order $order
     * @return array
     * @throws LocalizedException
     * @throws NoSuchEntityException
     */
    public function execute(Order\Shipment $shipment, Order $order): array
    {
        $shipmentItems = $this->getLineItemsShipment($order, $shipment);

        return [
            'discount_amount' => $this->getSum($shipmentItems, 'discount_amount'),
            'gross_amount' => $this->getSum($shipmentItems, 'gross_amount'),
            'line_items' => array_values($shipmentItems),
            'net_amount' => $this->getSum($shipmentItems, 'net_amount'),
            'tax_amount' => $this->getSum($shipmentItems, 'tax_amount'),
            'tax_subtotals' => $this->getTaxSubtotals($shipmentItems),
        ];
    }

    /**
     * @param Order $order
     * @param Order\Shipment $shipment
     * @return array
     * @throws LocalizedException
     */
    public function getLineItemsShipment(Order $order, Order\Shipment $shipment): array
    {
        $items = [];
        foreach ($shipment->getAllItems() as $item) {
            $orderItem = $this->getOrderItem((int)$item->getOrderItemId());
            if (!$item->getQty() || !$product = $this->getProduct($order, $orderItem)) {
                continue;
            }

            // Part of the item line that is shipped.
            $part = $item->getQty() / $orderItem->getQtyOrdered();

            $grossAmount = $this->roundAmt($this->getGrossAmountItem($orderItem) * $part);
            $netAmount = $this->roundAmt($this->getNetAmountItem($orderItem) * $part);
            $taxAmount = $grossAmount - $netAmount;

            $items[$orderItem->getItemId()] = [
                'order_item_id' => $item->getOrderItemId(),
                'name' => $item->getName(),
                'description' => $item->getName(),
                'gross_amount' => $grossAmount,
                'net_amount' => $netAmount,
                'tax_amount' => $taxAmount,
                'discount_amount' => $this->roundAmt($this->getDiscountAmountItem($orderItem) * $part),
                'tax_class_name' => 'VAT ' . $this->roundAmt($orderItem->getTaxPercent()) . '%',
                'tax_rate' => $this->roundAmt(($orderItem->getTaxPercent() / 100)),
                'unit_price' => $this->roundAmt($this->getUnitPriceItem($orderItem), 6),
                'quantity' => $item->getQty(),
                'quantity_unit' => $this->configRepository->getWeightUnit((int)$order->getStoreId()),
                'image_url' => $this->getProductImageUrl($product),
                'product_page_url' => $product->getProductUrl(),
                'type' => $orderItem->getIsVirtual() ? 'DIGITAL' : 'PHYSICAL',
                'details' => [
                    'barcodes' => [
                        [
                            'type' => 'SKU',
                            'value' => $item->getSku(),
                        ],
                    ],
                    'categories' => $this->getCategories($product->getCategoryIds()),
                ],
            ];
        }

        // Add shipping amount as orderLine on first shipment
        $firstShipmentId = $order->getShipmentsCollection()->getFirstItem()->getId();
        if ($firstShipmentId == $shipment->getId() && $order->getShippingAmount() > 0) {
            $items['shipping'] = $this->getShippingLineOrder($order);
        }

        return $items;
    }
}
