<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Service\Payment;

use Magento\Sales\Model\Order;

/**
 * What one check of a waiting order with Two found and did (TWO-26300).
 */
class PendingOrderResolution
{
    /** @var string one of PendingOrderResolver::OUTCOME_* */
    private $outcome;

    /** @var Order the order as it stands after the check */
    private $order;

    /** @var string the state Two reported, or '' when it was not read */
    private $twoState;

    /** @var string what was done, for the merchant */
    private $message;

    /** @var string on a failed buyer return, what the buyer is shown */
    private $buyerMessage;

    public function __construct(
        string $outcome,
        Order $order,
        string $twoState = '',
        string $message = '',
        string $buyerMessage = ''
    ) {
        $this->outcome = $outcome;
        $this->order = $order;
        $this->twoState = $twoState;
        $this->message = $message;
        $this->buyerMessage = $buyerMessage;
    }

    public function getOutcome(): string
    {
        return $this->outcome;
    }

    public function getOrder(): Order
    {
        return $this->order;
    }

    public function getTwoState(): string
    {
        return $this->twoState;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function getBuyerMessage(): string
    {
        return $this->buyerMessage;
    }

    /**
     * Whether the order is now processing in Magento, by this check or an
     * earlier one.
     */
    public function isProcessed(): bool
    {
        return in_array(
            $this->outcome,
            [
                PendingOrderResolver::OUTCOME_CONFIRMED,
                PendingOrderResolver::OUTCOME_PROCESSED,
                PendingOrderResolver::OUTCOME_ALREADY_PROCESSED,
            ],
            true
        );
    }
}
