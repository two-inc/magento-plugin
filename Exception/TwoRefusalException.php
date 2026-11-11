<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Exception;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;

/**
 * Two refused a request (TWO-26295). The message is the full account, for the
 * merchant: an admin notice or an order comment. A buyer-facing surface shows
 * getBuyerMessage() instead, which never carries the API's own text.
 */
class TwoRefusalException extends LocalizedException
{
    /** @var Phrase */
    private $buyerMessage;

    public function __construct(Phrase $message, Phrase $buyerMessage)
    {
        parent::__construct($message);
        $this->buyerMessage = $buyerMessage;
    }

    public function getBuyerMessage(): Phrase
    {
        return $this->buyerMessage;
    }
}
