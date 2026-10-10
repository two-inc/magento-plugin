<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Plugin\Model\Sales\FulfilAfterRefund;

use Magento\Sales\Api\CreditmemoManagementInterface;
use Magento\Sales\Api\Data\CreditmemoInterface;
use Two\Gateway\Service\Order\StatusFulfilment;

/**
 * The admin credit memo route: core saves the memo, then the order. A status
 * fulfilment the order save triggers waits until the call returns (TWO-26302).
 */
class CreditmemoManagement
{
    /** @var StatusFulfilment */
    private $statusFulfilment;

    public function __construct(StatusFulfilment $statusFulfilment)
    {
        $this->statusFulfilment = $statusFulfilment;
    }

    /**
     * @param CreditmemoManagementInterface $subject
     * @param callable $proceed
     * @param CreditmemoInterface $creditmemo
     * @param bool $offlineRequested
     * @return CreditmemoInterface
     */
    public function aroundRefund(
        CreditmemoManagementInterface $subject,
        callable $proceed,
        CreditmemoInterface $creditmemo,
        $offlineRequested = false
    ) {
        return $this->statusFulfilment->duringRefund(
            static fn () => $proceed($creditmemo, $offlineRequested)
        );
    }
}
