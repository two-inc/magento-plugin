<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Service\Order;

use Magento\Sales\Model\Order as OrderModel;
use Magento\Sales\Model\Order\Creditmemo;
use Two\Gateway\Api\Log\RepositoryInterface as LogRepository;
use Two\Gateway\Model\Two as TwoPayment;

/**
 * The order-level "other charges" residual, for consumers outside the payload
 * composition path — a credit-memo total collector has to know what an
 * unitemized fee is worth before the credit memo carries any of it.
 *
 * Mirrors reconcileOtherCharges(): fee-provider lines are merged before the
 * residual is reconciled, so a fee a provider already itemizes is never
 * counted twice.
 */
class OtherChargesResolver
{
    private ComposeRefund $composeRefund;

    private LogRepository $logRepository;

    public function __construct(ComposeRefund $composeRefund, LogRepository $logRepository)
    {
        $this->composeRefund = $composeRefund;
        $this->logRepository = $logRepository;
    }

    /**
     * The order's unitemized charge as a line item, or null when the order's
     * grand total is fully accounted for.
     *
     * @param OrderModel $order
     * @return array|null Order::getOtherChargesLineItem() shape.
     */
    public function forOrder(OrderModel $order): ?array
    {
        if (!$this->appliesTo($order)) {
            return null;
        }

        try {
            $lineItems = $this->composeRefund->getKnownLineAmountsOrder($order);
            foreach ($this->composeRefund->getFeeLines($order) as $feeLine) {
                $lineItems[] = $feeLine;
            }

            return $this->composeRefund->getOtherChargesLineItem(
                $lineItems,
                $order,
                (float)$order->getGrandTotal(),
                (float)$order->getTaxAmount()
            );
        } catch (\Throwable $e) {
            // Refusing here costs the merchant the refund, so it is an error.
            $this->logRepository->addErrorLog(
                'OtherChargesResolver',
                'Could not resolve the order residual: ' . $e->getMessage()
            );

            return null;
        }
    }

    /**
     * A fee extension applies store-wide, so this decides whose refund totals
     * this module may move. By payment-method INSTANCE, not code: a brand
     * overlay's GenericPaymentMethod extends Two under its own per-brand code,
     * so a code comparison would miss every branded install.
     *
     * @param OrderModel $order
     * @return bool
     */
    public function appliesTo(OrderModel $order): bool
    {
        $payment = $order->getPayment();
        if (!$payment) {
            return false;
        }

        try {
            return $payment->getMethodInstance() instanceof TwoPayment;
        } catch (\Throwable $e) {
            // getMethodInstance() throws for a method no longer installed.
            return false;
        }
    }

    /**
     * What earlier credit memos already took of the order's charge, and the
     * subtotal they refunded.
     *
     * From the saved memos, not a running column, so a re-collect on the
     * current memo cannot compound.
     *
     * @param OrderModel $order
     * @param Creditmemo|null $current Excluded from the sum.
     * @return array{0: float, 1: float} charge already refunded, subtotal already refunded
     */
    public function priorRefunds(OrderModel $order, ?Creditmemo $current = null): array
    {
        $collection = $order->getCreditmemosCollection();
        if (!$collection) {
            return [0.0, 0.0];
        }

        $charge = 0.0;
        $subtotal = 0.0;
        foreach ($collection as $existing) {
            if (!$existing->getId()
                || ($current && (int)$existing->getId() === (int)$current->getId())
            ) {
                continue;
            }
            $charge += (float)$existing->getTwoOtherChargesAmount();
            $subtotal += (float)$existing->getSubtotal();
        }

        return [$charge, $subtotal];
    }
}
