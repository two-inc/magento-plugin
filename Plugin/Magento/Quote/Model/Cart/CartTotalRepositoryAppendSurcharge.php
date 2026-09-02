<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Plugin\Magento\Quote\Model\Cart;

use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Api\CartTotalRepositoryInterface;
use Magento\Quote\Api\Data\TotalsInterface;
use Magento\Quote\Api\Data\TotalSegmentInterface;
use Magento\Quote\Api\Data\TotalSegmentInterfaceFactory;
use Magento\Framework\Phrase;
use Two\Gateway\Service\Order\SurchargeDisplay;

/**
 * Append the `two_surcharge` total segment to the cart-totals API response
 * whenever the active quote address has a non-zero `two_surcharge_amount`.
 *
 * Why this plugin exists:
 *
 * Magento's `CartTotalRepository::get($cartId)` for non-virtual quotes
 * reads the segments via `$quote->getShippingAddress()->getTotals()`,
 * which is the in-memory `_totals` array populated only when
 * `Quote::collectTotals()` has been called on THAT quote instance.
 * Hyvä's PriceSummary view-model does call `collectTotals()` (via
 * `reCollectTotalsInTotalSegments` workaround) but on a SEPARATE
 * `sessionCheckout->getQuote()` instance — `CartTotalRepository::get()`
 * then loads a fresh quote via `quoteRepository->getActive($cartId)`
 * whose `_totals` is empty. Magento's standard segments (subtotal /
 * discount / shipping / tax / grand_total) survive this round-trip
 * because they are persisted as DB columns on `quote_address` and
 * synthesised back into segments by other layers; our `two_surcharge`
 * segment is NOT synthesised that way and silently goes missing.
 *
 * The Hyva-rendered order summary shows correct grand total (which
 * includes the surcharge persisted as the `two_surcharge_amount`
 * column) but no surcharge line. Buyer sees a mismatch.
 *
 * Fix: an `after` plugin on `CartTotalRepositoryInterface::get` that
 * checks the relevant quote address for `two_surcharge_amount > 0`
 * and appends a `two_surcharge` segment to the returned totals if
 * one is not already present. Idempotent: pre-existing segment is
 * left untouched.
 */
class CartTotalRepositoryAppendSurcharge
{
    public function __construct(
        private readonly CartRepositoryInterface $quoteRepository,
        private readonly TotalSegmentInterfaceFactory $totalSegmentFactory,
        private readonly SurchargeDisplay $surchargeDisplay
    ) {
    }

    /**
     * @param mixed $cartId
     */
    public function afterGet(
        CartTotalRepositoryInterface $subject,
        TotalsInterface $result,
        $cartId
    ): TotalsInterface {
        try {
            $quote = $this->quoteRepository->getActive($cartId);
        } catch (\Exception $e) {
            return $result;
        }

        $address = $quote->isVirtual()
            ? $quote->getBillingAddress()
            : $quote->getShippingAddress();

        if (!$address) {
            return $result;
        }

        $surchargeAmount = (float) $address->getData('two_surcharge_amount');
        if ($surchargeAmount <= 0) {
            return $result;
        }

        $segments = $result->getTotalSegments() ?? [];
        foreach ($segments as $segment) {
            if ($segment->getCode() === 'two_surcharge') {
                return $result;
            }
        }

        $title = (string) $address->getData('two_surcharge_description');
        if ($title === '') {
            $title = (string) (new Phrase('Payment terms fee'));
        }

        $tax = (float) $address->getData('two_surcharge_tax_amount');
        $mode = $this->surchargeDisplay->forCart($quote->getStore());

        if ($mode === SurchargeDisplay::BOTH) {
            $segments[] = $this->buildSegment(
                'two_surcharge',
                (string) __('%1 (Excl. Tax)', $title),
                $surchargeAmount
            );
            $segments[] = $this->buildSegment(
                'two_surcharge_incl',
                (string) __('%1 (Incl. Tax)', $title),
                $surchargeAmount + $tax
            );
        } else {
            $segments[] = $this->buildSegment(
                'two_surcharge',
                $title,
                $this->surchargeDisplay->pick($mode, $surchargeAmount, $tax)
            );
        }

        $result->setTotalSegments($segments);

        return $result;
    }

    private function buildSegment(string $code, string $title, float $value): TotalSegmentInterface
    {
        $segment = $this->totalSegmentFactory->create();
        $segment->setData([
            'code'  => $code,
            'title' => $title,
            'value' => $value,
            'area'  => null,
        ]);

        return $segment;
    }
}
