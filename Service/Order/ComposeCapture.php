<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Service\Order;

use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Model\Order;
use Two\Gateway\Service\Order as OrderService;

/**
 * Compose Capture Service
 */
class ComposeCapture extends OrderService
{
    /**
     * Compose request body for two capture order
     *
     * @param Order\Invoice $invoice
     * @param string|null $twoOriginalOrderId
     * @return array
     * @throws LocalizedException
     */
    public function execute(Order\Invoice $invoice, ?string $twoOriginalOrderId = ''): array
    {
        $order = $invoice->getOrder();
        $lineItems = $this->getLineItemsInvoice($invoice, $order);
        $reqBody = [
            'discount_amount' => $this->roundAmt(abs((float)$invoice->getDiscountAmount())),
            'gross_amount' => $this->roundAmt($invoice->getGrandTotal()),
            'line_items' => $lineItems,
            'net_amount' => $this->roundAmt($invoice->getGrandTotal() - $invoice->getTaxAmount()),
            'tax_amount' => $this->roundAmt($invoice->getTaxAmount()),
            'tax_subtotal' => $this->getTaxSubtotals($lineItems),
        ];
        return $reqBody;
    }

    /**
     * Get Invoice Line Items
     *
     * @param Order\Invoice $invoice
     * @param Order $order
     * @return array
     * @throws LocalizedException
     */
    public function getLineItemsInvoice(Order\Invoice $invoice, Order $order): array
    {
        $items = [];
        foreach ($invoice->getAllItems() as $item) {
            if ($item->getQty() > 0) {
                $orderItem = $order->getItemById($item->getOrderItemId());
                if (!$product = $this->getProduct($order, $item)) {
                    continue;
                }

                $items[] = [
                    'order_item_id' => $item->getOrderItemId(),
                    'name' => $item->getName(),
                    'description' => $item->getName(),
                    'gross_amount' => $this->roundAmt($this->getGrossAmountItem($item)),
                    'net_amount' => $this->roundAmt($this->getNetAmountItem($item)),
                    'discount_amount' => $this->roundAmt($this->getDiscountAmountItem($item)),
                    'tax_amount' => $this->roundAmt($this->getTaxAmountItem($item)),
                    'tax_class_name' => 'VAT ' . $this->roundAmt($orderItem->getTaxPercent()) . '%',
                    'tax_rate' => $this->roundAmt(($orderItem->getTaxPercent() / 100)),
                    'unit_price' => $this->roundAmt($this->getUnitPriceItem($item), 6),
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
                            ]
                        ],
                        'categories' => $this->getCategories($product->getCategoryIds()),
                    ]
                ];
            }
        }

        if ($order->getShippingAmount() != 0) {
            $taxRate = round((1.0 * $order->getShippingTaxAmount() / $order->getShippingAmount()), 6);
            $items[] = [
                'order_item_id' => 'shipping',
                'name' => 'Shipping - ' . $order->getShippingMethod(),
                'description' => '',
                'type' => 'SHIPPING_FEE',
                'image_url' => '',
                'product_page_url' => '',
                'gross_amount' => $this->roundAmt($this->getGrossAmountShipping($order)),
                'net_amount' => $this->roundAmt($this->getNetAmountShipping($order)),
                'tax_amount' => $this->roundAmt((float)$order->getShippingTaxAmount()),
                'discount_amount' => $this->roundAmt($this->getDiscountAmountShipping($order)),
                'tax_rate' => $this->roundAmt((1.0 * $order->getShippingTaxAmount() / $order->getShippingAmount())),
                'unit_price' => $this->roundAmt($this->getUnitPriceShipping($order), 6),
                'tax_class_name' => 'VAT ' . $this->roundAmt($taxRate * 100) . '%',
                'quantity' => 1,
                'quantity_unit' => 'sc',
            ];
        }

        return $items;
    }

    /**
     * @param Order $order
     * @return array
     */
    private function getShippingDetails(Order $order): array
    {
        $trackNumber = '';
        $carrierName = '';

        $shipments = $order->getShipmentsCollection();
        foreach ($shipments as $shipment) {
            $tracksCollection = $shipment->getTracksCollection();
            foreach ($tracksCollection->getItems() as $track) {
                $trackNumber = $track->getTrackNumber();
                $carrierName = $track->getTitle();
            }
        }

        return [
            'carrier_name' => $carrierName,
            'tracking_number' => $trackNumber,
            'expected_delivery_date' => date('Y-m-d', strtotime('+ 7 days'))
        ];
    }
}
