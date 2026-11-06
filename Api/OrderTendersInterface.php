<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Api;

use Magento\Quote\Model\Quote;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Creditmemo;
use Magento\Sales\Model\Order\Invoice;

/**
 * The tenders of an order, quote, invoice or credit memo (TWO-26309): what has
 * already paid part of it without being a line, such as a gift card, store
 * credit or reward points. Its amount is in the lines but not in the grand
 * total, which is what is left to pay. Discounts are not tenders: they are on
 * the lines already.
 *
 * The plugin's own implementation reads Adobe Commerce's gift card account,
 * store credit and reward points totals, and finds none on Magento Open
 * Source. To declare another tender, add an `after` plugin on collect() that
 * appends to the list it returns. Each entry:
 *
 * - `type`: TYPE_GIFT_CARD, TYPE_STORE_CREDIT or TYPE_OTHER (anything else is reported as other)
 * - `label`: a name for logs and display; never a redeemable card code
 * - `amount`: what it paid, positive, in the entity's (order) currency
 * - `source`: where it was read from
 *
 * An entry whose amount is zero is skipped; one that is negative or not a
 * number is dropped and logged with its type, source and amount, never its
 * label. See the README section "Stable extension contract: order
 * postprocessing".
 *
 * @api
 */
interface OrderTendersInterface
{
    public const TYPE_GIFT_CARD = 'gift_card';
    public const TYPE_STORE_CREDIT = 'store_credit';
    public const TYPE_OTHER = 'other';

    /**
     * @param Order|Quote|Invoice|Creditmemo $entity
     * @return array<int, array{type: string, label: string, amount: float|string, source: string}>
     */
    public function collect($entity): array;
}
