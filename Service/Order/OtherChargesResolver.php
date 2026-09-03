<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Service\Order;

use Magento\Sales\Model\Order as OrderModel;
use Two\Gateway\Api\Log\RepositoryInterface as LogRepository;

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
}
