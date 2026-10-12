<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Service\Order;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Api\Data\OrderItemInterface;
use Magento\Sales\Api\Data\ShipmentInterface;
use Magento\Sales\Api\Data\ShipmentItemInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Creditmemo;
use Two\Gateway\Service\Order as OrderService;

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
        return $this->withPrepaid(
            $this->payload($this->getLineItemsShipment($order, $shipment), $order),
            $order,
            $this->fulfilledBefore($order, $shipment)
        );
    }

    /**
     * Compose a fulfilment of everything on the order not refunded or
     * cancelled in Magento (TWO-26302): what the buyer is to pay for when the
     * merchant refunded part of the order before it was fulfilled. Shipping
     * goes in only while none of it was refunded. This is the only fulfilment
     * the order gets, so it also carries whatever of the surcharge, the
     * fee-provider lines and the other-charges residual is not yet refunded.
     *
     * @param Order $order
     * @return array
     * @throws LocalizedException
     * @throws NoSuchEntityException
     */
    public function executeNetOfRefunds(Order $order): array
    {
        $items = $this->getProductLinesNetOfRefunds($order);
        foreach ($this->getChargeLinesNetOfRefunds($order) as $line) {
            $items[] = $line;
        }

        return $this->withPrepaid($this->payload($items, $order), $order, 0.0);
    }

    /**
     * The order's saved credit memos that refunded something.
     *
     * A fresh query, not $order->getCreditmemosCollection(): the order caches
     * that collection once loaded, and the memo being saved in this same
     * refund (the one moving the order into the fulfil-on status) is not in it
     * when OtherCharges::collect() loaded it first. A cancelled memo refunded
     * nothing.
     *
     * @param Order $order
     * @return Creditmemo[]
     */
    private function savedMemos(Order $order): array
    {
        if (!$this->creditmemoCollectionFactory) {
            // Null only where a test skipped the constructor; etc/di.xml names it.
            return [];
        }
        $memos = [];
        foreach ($this->creditmemoCollectionFactory->create()->setOrderFilter($order) as $memo) {
            if ($memo->getId() && (int)$memo->getState() !== Creditmemo::STATE_CANCELED) {
                $memos[] = $memo;
            }
        }

        return $memos;
    }

    /**
     * Declare the order's tenders on its first fulfilments (TWO-26309): each
     * partial takes as prepaid what the tenders have left, up to its own
     * lines, so the part of the order not yet fulfilled never carries more
     * prepaid than invoice. Magento takes tenders off the first invoices the
     * same way. What the credit memos already returned to the tenders (Magento
     * refunds a gift card or store credit before the payment) is no longer
     * paid, so it comes off first. With no tenders the body is unchanged.
     *
     * @param array $payload a partial built by payload()
     * @param Order $order
     * @param float $fulfilledBefore the lines' gross of the partials sent before this one
     * @return array
     */
    private function withPrepaid(array $payload, Order $order, float $fulfilledBefore): array
    {
        $tendered = (int)round($this->getTenderTotal($order) * 100);
        if ($tendered <= 0 || $payload['line_items'] === []) {
            return $payload;
        }
        foreach ($this->savedMemos($order) as $memo) {
            $tendered -= (int)round($this->getTenderTotal($memo) * 100);
        }
        $tendered = max(0, $tendered);
        $invoice = (int)round((float)$payload['net_amount'] * 100) + (int)round((float)$payload['tax_amount'] * 100);
        $prepaid = max(0, min($tendered - min($tendered, (int)round($fulfilledBefore * 100)), $invoice));
        $payload['invoice_amount'] = $this->roundAmt($invoice / 100);
        $payload['payable_amount'] = $this->roundAmt(($invoice - $prepaid) / 100);
        $payload['prepaid_amount'] = $this->roundAmt($prepaid / 100);
        $payload['gross_amount'] = $payload['payable_amount'];
        ksort($payload);

        return $payload;
    }

    /**
     * The lines' gross of the order's shipments before this one, each
     * composed as its own partial was. Only read for an order with tenders.
     *
     * @param Order $order
     * @param Order\Shipment $shipment
     * @return float
     */
    private function fulfilledBefore(Order $order, Order\Shipment $shipment): float
    {
        if ($this->getTenderTotal($order) <= 0) {
            return 0.0;
        }
        $sum = 0.0;
        foreach ($order->getShipmentsCollection() as $previous) {
            $id = (int)$previous->getId();
            if ($id === 0 || $id === (int)$shipment->getId() || ($shipment->getId() && $id > (int)$shipment->getId())) {
                continue;
            }
            foreach ($this->getLineItemsShipment($order, $previous) as $line) {
                $sum += (float)$line['gross_amount'];
            }
        }

        return round($sum, 2);
    }

    /**
     * @param Order $order
     * @return array
     * @throws LocalizedException
     */
    private function getProductLinesNetOfRefunds(Order $order): array
    {
        $items = [];
        foreach ($order->getAllVisibleItems() as $orderItem) {
            $qty = (float)$orderItem->getQtyOrdered()
                - (float)$orderItem->getQtyRefunded()
                - (float)$orderItem->getQtyCanceled();
            $line = $qty > 0
                ? $this->composeLine($order, $orderItem, $orderItem->getItemId(), $qty, $orderItem->getName(), $orderItem->getSku())
                : null;
            if ($line !== null) {
                $items[$orderItem->getItemId()] = $line;
            }
        }
        if ($order->getShippingAmount() > 0 && (float)$order->getShippingRefunded() <= 0) {
            $items['shipping'] = $this->getShippingLineOrder($order);
        }

        return $items;
    }

    /**
     * The surcharge, each fee-provider line and the other-charges residual,
     * each less what the order's credit memos already refunded of it. A charge
     * with nothing left is left out.
     *
     * @param Order $order
     * @return array
     * @throws LocalizedException
     */
    private function getChargeLinesNetOfRefunds(Order $order): array
    {
        $memos = $this->savedMemos($order);

        $lines = [];
        $surchargeNet = (float)$order->getTwoSurchargeAmount();
        if ($surchargeNet > 0) {
            $lines[] = $this->remainderOf(
                $this->getSurchargeLine(
                    $surchargeNet,
                    (float)$order->getTwoSurchargeTaxAmount(),
                    (string)$order->getTwoSurchargeDescription(),
                    (float)$order->getTwoSurchargeTaxRate()
                ),
                (float)$order->getTwoSurchargeRefunded(),
                [$surchargeNet, (float)$order->getTwoSurchargeTaxAmount()]
            );
        }

        // A provider's lines on each memo say what that memo refunded of the fee.
        $refundedFees = [];
        foreach ($memos as $memo) {
            foreach ($this->getFeeLines($memo) as $feeLine) {
                $id = (string)($feeLine['order_item_id'] ?? '');
                $refundedFees[$id] = ($refundedFees[$id] ?? 0.0) + (float)$feeLine['net_amount'];
            }
        }
        foreach ($this->getFeeLines($order) as $feeLine) {
            $lines[] = $this->remainderOf($feeLine, $refundedFees[(string)($feeLine['order_item_id'] ?? '')] ?? 0.0);
        }

        $residual = $this->getOtherChargesLineOrder($order);
        if ($residual) {
            $refundedCharge = 0.0;
            foreach ($memos as $memo) {
                $refundedCharge += (float)$memo->getTwoOtherChargesAmount();
            }
            $lines[] = $this->remainderOf($residual, $refundedCharge);
        }

        return array_values(array_filter($lines));
    }

    /**
     * $line less $refundedNet of its net, its tax shrunk in proportion so the
     * line keeps its declared rate. The line as it is when nothing was
     * refunded, so the fulfilment matches the order line exactly; null when
     * nothing a line can carry is left. $source is the unrounded [net, tax]
     * the line was built from, where there is one, so a refund of a sub-cent
     * source is taken from that source and not from the rounded line.
     *
     * @param array $line
     * @param float $refundedNet
     * @param array|null $source
     * @return array|null
     */
    private function remainderOf(array $line, float $refundedNet, ?array $source = null): ?array
    {
        if ($refundedNet <= 0) {
            return $line;
        }
        [$net, $tax] = $source ?? [(float)$line['net_amount'], (float)$line['tax_amount']];
        $remainingNet = round($net - $refundedNet, 6);
        if (round($remainingNet, 2) <= 0) {
            return null;
        }
        $remainingTax = $tax * $remainingNet / $net;

        return array_merge($line, [
            'gross_amount' => $this->roundAmt($remainingNet + $remainingTax),
            'net_amount' => $this->roundAmt($remainingNet),
            'tax_amount' => $this->roundAmt($remainingTax),
            'unit_price' => $this->roundAmt($remainingNet, 6),
            'quantity' => 1,
        ]);
    }

    /**
     * @param array $lineItems
     * @param Order $order
     * @return array
     */
    private function payload(array $lineItems, Order $order): array
    {
        $shipmentItems = $this->applyTaxCodes($lineItems, $order);

        // Deliberately no getFeeLines()/getOtherChargesLineItem() call here,
        // unlike ComposeOrder/ComposeCapture/ComposeRefund: every total
        // below is summed directly from $shipmentItems, so they cannot
        // diverge from sum(line_items) by construction. There is no
        // independent grand-total column to reconcile against.
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
            $line = $item->getQty()
                ? $this->composeLine($order, $orderItem, $item->getOrderItemId(), $item->getQty(), $item->getName(), $item->getSku())
                : null;
            if ($line !== null) {
                $items[$orderItem->getItemId()] = $line;
            }
        }

        // Add shipping amount as orderLine on first shipment
        $firstShipmentId = $order->getShipmentsCollection()->getFirstItem()->getId();
        if ($firstShipmentId == $shipment->getId() && $order->getShippingAmount() > 0) {
            $items['shipping'] = $this->getShippingLineOrder($order);
        }

        return $items;
    }

    /**
     * One product line for $qty of the order item, prorated from the item.
     *
     * @param Order $order
     * @param Order\Item $orderItem
     * @param mixed $orderItemId
     * @param mixed $qty
     * @param mixed $name
     * @param mixed $sku
     * @return array|null Null when the product no longer loads.
     */
    private function composeLine(Order $order, Order\Item $orderItem, $orderItemId, $qty, $name, $sku): ?array
    {
        if (!$product = $this->getProduct($order, $orderItem)) {
            return null;
        }

        // Part of the item line being fulfilled.
        $part = $qty / $orderItem->getQtyOrdered();

        $grossAmount = $this->roundAmt($this->getGrossAmountItem($orderItem) * $part);
        $netAmount = $this->roundAmt($this->getNetAmountItem($orderItem) * $part);
        $taxAmount = $grossAmount - $netAmount;

        return [
            'order_item_id' => $orderItemId,
            'name' => $name,
            'description' => $name,
            'gross_amount' => $grossAmount,
            'net_amount' => $netAmount,
            'tax_amount' => $taxAmount,
            'discount_amount' => $this->roundAmt($this->getDiscountAmountItem($orderItem) * $part),
            'tax_class_name' => 'VAT ' . $this->roundAmt($orderItem->getTaxPercent()) . '%',
            'tax_rate' => $this->roundAmt(($orderItem->getTaxPercent() / 100), 6),
            'unit_price' => $this->roundAmt($this->getUnitPriceItem($orderItem), 6),
            'quantity' => $qty,
            'quantity_unit' => $this->configRepository->getWeightUnit((int)$order->getStoreId()),
            'image_url' => $this->getProductImageUrl($product),
            'product_page_url' => $product->getProductUrl(),
            'type' => $orderItem->getIsVirtual() ? 'DIGITAL' : 'PHYSICAL',
            'details' => [
                'barcodes' => [
                    [
                        'type' => 'SKU',
                        'value' => $sku,
                    ],
                ],
                'categories' => $this->getCategories($product->getCategoryIds()),
            ],
        ];
    }
}
