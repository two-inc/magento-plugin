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

        // Reconcile any known third-party fee (via a registered
        // FeeLineProviderInterface) and, failing that, any genuinely
        // untaxed residual. See Order::reconcileOtherCharges() docblock.
        //
        // NOTE (known, separate, not fixed here): unlike the shipping line
        // above and ComposeShipment's first-shipment guard, this method has
        // no "first invoice only" guard around a fee proration. If a future
        // provider needs to itemize a fee that Magento's totals collector
        // only applies once (e.g. on the first invoice, mirroring how
        // shipping is invoiced once), that provider is responsible for its
        // own idempotency — this call site doesn't provide one.
        $lineItems = $this->reconcileOtherCharges(
            $lineItems,
            $invoice,
            (float)$invoice->getGrandTotal(),
            (float)$invoice->getTaxAmount()
        );

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
                    'tax_rate' => $this->roundAmt(($orderItem->getTaxPercent() / 100), 6),
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
            $taxRate = $this->getTaxRateShipping($order);
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
                'tax_rate' => $this->roundAmt($taxRate, 6),
                'unit_price' => $this->roundAmt($this->getUnitPriceShipping($order), 6),
                'tax_class_name' => 'VAT ' . $this->roundAmt($taxRate * 100) . '%',
                'quantity' => 1,
                'quantity_unit' => 'sc',
            ];
        }

        // The surcharge remaining on THIS invoice — populated by
        // Model\Total\Invoice\Surcharge::collect() specifically so it can be
        // itemized here. Pre-existing gap this PR closes: without this line,
        // invoice.grand_total/tax_amount already carry the surcharge's net
        // and tax (per that collector's own comment) but line_items never
        // did, so the new getOtherChargesLineItem() fallback would otherwise
        // mistake Two's own known BUYER_FEE for an unrecognized third-party
        // fee on every capture of a surcharge-bearing order.
        $invoiceSurchargeAmount = (float)$invoice->getTwoSurchargeAmount();
        if ($invoiceSurchargeAmount > 0) {
            $invoiceSurchargeTax = (float)$invoice->getTwoSurchargeTaxAmount();
            $description = (string)$invoice->getTwoSurchargeDescription() ?: (string)__('Payment terms fee');
            $taxRatePercent = (float)$invoice->getTwoSurchargeTaxRate();

            $items[] = [
                'order_item_id' => 'surcharge',
                'name' => $description,
                'description' => $description,
                'type' => 'BUYER_FEE',
                'image_url' => '',
                'product_page_url' => '',
                'gross_amount' => $this->roundAmt($invoiceSurchargeAmount + $invoiceSurchargeTax),
                'net_amount' => $this->roundAmt($invoiceSurchargeAmount),
                'tax_amount' => $this->roundAmt($invoiceSurchargeTax),
                'discount_amount' => '0.00',
                'tax_rate' => $this->roundAmt($taxRatePercent / 100, 6),
                'tax_class_name' => 'VAT ' . $this->roundAmt($taxRatePercent) . '%',
                'unit_price' => $this->roundAmt($invoiceSurchargeAmount, 6),
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
