<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Api\Fee;

/**
 * Contract for a provider that itemizes ONE specific known third-party
 * fee-type total — a totals-collector amount that bumps grand_total the
 * same way Magento's own shipping total does, without being a quote/order
 * item (e.g. Amasty's "Extra Fee" module) — into real line item(s) with
 * that fee's actual gross/net/tax amounts and tax rate.
 *
 * Registered providers run before Order::getOtherChargesLineItem()'s
 * generic residual fallback, so any residual the fallback still has to
 * reconcile is smaller (or gone), and the fallback never has to guess at
 * an unknown fee's real tax rate.
 *
 * No providers are registered by default (see etc/di.xml): building one
 * for a specific extension requires that extension's real field/table
 * names, verified against an actual install, not guessed at from
 * documentation.
 */
interface FeeLineProviderInterface
{
    /**
     * Return zero or more fully-formed line items (same shape as
     * Order::getLineItemsOrder()'s entries) for the fee(s) this provider
     * knows how to itemize on the given entity. Return an empty array if
     * the fee this provider targets isn't present/active on the entity —
     * never throw for "not applicable".
     *
     * @param \Magento\Sales\Model\Order|\Magento\Sales\Model\Order\Invoice|\Magento\Sales\Model\Order\Creditmemo $entity
     * @return array[]
     */
    public function getFeeLines($entity): array;
}
