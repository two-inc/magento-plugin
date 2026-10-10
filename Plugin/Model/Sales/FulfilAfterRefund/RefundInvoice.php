<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Plugin\Model\Sales\FulfilAfterRefund;

use Magento\Sales\Api\Data\CreditmemoCommentCreationInterface;
use Magento\Sales\Api\Data\CreditmemoCreationArgumentsInterface;
use Magento\Sales\Api\RefundInvoiceInterface;
use Two\Gateway\Service\Order\StatusFulfilment;

/**
 * The REST invoice refund route: core saves the order before the credit memo,
 * inside the order lock's transaction. A status fulfilment the order save
 * triggers waits until the call returns (TWO-26302).
 */
class RefundInvoice
{
    /** @var StatusFulfilment */
    private $statusFulfilment;

    public function __construct(StatusFulfilment $statusFulfilment)
    {
        $this->statusFulfilment = $statusFulfilment;
    }

    /**
     * @param RefundInvoiceInterface $subject
     * @param callable $proceed
     * @param int $invoiceId
     * @param array $items
     * @param bool $isOnline
     * @param bool $notify
     * @param bool $appendComment
     * @param CreditmemoCommentCreationInterface|null $comment
     * @param CreditmemoCreationArgumentsInterface|null $arguments
     * @return int the credit memo id
     */
    public function aroundExecute(
        RefundInvoiceInterface $subject,
        callable $proceed,
        $invoiceId,
        array $items = [],
        $isOnline = false,
        $notify = false,
        $appendComment = false,
        ?CreditmemoCommentCreationInterface $comment = null,
        ?CreditmemoCreationArgumentsInterface $arguments = null
    ) {
        return $this->statusFulfilment->duringRefund(
            static fn () => $proceed($invoiceId, $items, $isOnline, $notify, $appendComment, $comment, $arguments)
        );
    }
}
