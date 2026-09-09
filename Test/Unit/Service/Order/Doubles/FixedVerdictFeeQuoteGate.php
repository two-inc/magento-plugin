<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Service\Order\Doubles;

use Magento\Quote\Api\Data\CartInterface;
use Two\Gateway\Service\Order\FeeQuoteGate;

/**
 * Subclassed rather than mocked: a mock's default verdict is false, which
 * would agree with a withholding expectation by accident.
 */
class FixedVerdictFeeQuoteGate extends FeeQuoteGate
{
    public function __construct(private bool $quotable)
    {
    }

    public function isQuotable(?CartInterface $quote, ?int $storeId): bool
    {
        return $this->quotable;
    }
}
