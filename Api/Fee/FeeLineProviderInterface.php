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
 * item — into real line item(s) with that fee's actual gross/net/tax
 * amounts and tax rate, by reading that fee directly from the vendor's
 * own data.
 *
 * Only needed for a fee extension that computes its OWN tax outside
 * Magento's tax engine. An extension that registers its tax with Magento
 * normally (e.g. via the quote address's `applied_taxes` total data —
 * Amasty's "Extra Fee" module included) is already reconciled generically
 * by Order::findVerifiedResidualTaxRate(), with no provider needed. See
 * that method's docblock.
 *
 * Registered providers run before Order::getOtherChargesLineItem()'s
 * fallback chain, so any residual it still has to reconcile is smaller
 * (or gone).
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
