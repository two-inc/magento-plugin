<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Observer;

use Two\Gateway\Api\Log\RepositoryInterface as LogRepository;
use Magento\Framework\DB\Sql\Expression;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
/**
 * Bumps `order.two_surcharge_invoiced` once per invoice creation.
 *
 * The invoice total collector sets the surcharge fields on the invoice but
 * deliberately does NOT touch the order — collectTotals() is called multiple
 * times per save (prepareInvoice, register, etc.) so any in-collect mutation
 * would double-count. This observer fires on save_after and is gated by the
 * "is-new" check so a re-save of an already-persisted invoice is a no-op.
 *
 * Concurrency model:
 *
 *  - The bump is issued as an additive SQL UPDATE rather than a read-
 *    modify-write through the order setter, so two concurrent invoice
 *    saves can't lose an increment to the baseline-clobber race
 *   . The delta is `number_format($amount, 6, '.', '')` so
 *    the SQL literal is deterministic 6dp (matches the internal-
 *    precision invariant — see Model/Total/Surcharge — and sidesteps
 *    scientific notation that PHP's `(string)$f` can produce for very
 *    small floats).
 *
 *  - The cap (`invoiced ≤ two_surcharge_amount`) is enforced at the DB
 *    level via a guarded WHERE: if the strict additive UPDATE would
 *    exceed the cap (because a concurrent observer also bumped the
 *    invoiced total), the WHERE doesn't match and affected-rows is 0.
 *    We then issue a LEAST-clamped UPDATE so the running total ends
 *    at exactly the cap, and log the clamp for operator
 *    investigation. The customer's invoice/refund flow is unaffected
 *    — Magento and Two API enforce their own caps on actual money;
 *    this observer only governs our internal running-total accounting
 *   .
 *
 *  - Direct UPDATE (rather than orderRepository->save()) avoids re-
 *    firing sales_order_save_after, which would risk re-entering the
 *    deferred-invoice flow in Observer\SalesOrderSaveAfter.
 */
class InvoiceSurchargeRunningTotal implements ObserverInterface
{
    /**
     * @var LogRepository
     */
    private $logRepository;

    public function __construct(LogRepository $logRepository)
    {
        $this->logRepository = $logRepository;
    }

    public function execute(Observer $observer): void
    {
        $invoice = $observer->getEvent()->getInvoice();
        if (!$invoice) {
            return;
        }
        // Only bump on the initial persist of a new invoice. getOrigData
        // returns the value loaded from the DB; null means the row didn't
        // exist before this save (i.e. this is the create save).
        if ($invoice->getOrigData('entity_id') !== null) {
            return;
        }

        $amount = (float)$invoice->getTwoSurchargeAmount();
        if ($amount <= 0) {
            return;
        }
        $baseAmount = (float)$invoice->getBaseTwoSurchargeAmount();

        $order = $invoice->getOrder();
        if (!$order || !$order->getId()) {
            return;
        }

        $resource = $order->getResource();
        $connection = $resource->getConnection();
        $table = $resource->getMainTable();
        $delta = number_format($amount, 6, '.', '');
        $baseDelta = number_format($baseAmount, 6, '.', '');
        $orderId = (int)$order->getId();

        // Two concurrency mechanisms stacked on this UPDATE — different
        // invariants, different parts of the statement:
        //
        // SET — closes the lost-update race. `column + delta`
        // is atomic at the InnoDB row-lock level: lock, read, compute,
        // write, release in one shot. Concurrent writers serialize
        // through the lock and compose correctly (A bumps to X+a, then
        // B reads X+a and bumps to X+a+b). NOT a CAS / optimistic-lock
        // pattern — a true CAS would read a baseline into PHP and gate
        // the UPDATE on `WHERE column = $baseline` with retry-on-
        // mismatch. Additive arithmetic has no contended baseline so we
        // don't need that.
        //
        // WHERE — closes the cap-violation race. Even with
        // atomic increments, two concurrent writers can both push past
        // the order's `two_surcharge_amount` cap if each is admissible
        // alone but their sum isn't. The `<=` guards check the post-
        // condition: would the result land within the cap? If yes, the
        // row matches and the UPDATE fires; if no, 0 rows affected and
        // we fall through to the LEAST-clamped fallback below. `<=`,
        // not `=`, because the cap is a maximum — any post-state in
        // [0, cap] is valid; equality would only permit increments that
        // land exactly at the cap, rejecting most legitimate ones.
        //
        // Both order-currency and base-currency caps guarded
        // independently in the AND-chain — FX divergence (one ccy fits,
        // the other doesn't) correctly falls through to clamp.
        $strictAffected = $connection->update(
            $table,
            [
                'two_surcharge_invoiced' => new Expression('two_surcharge_invoiced + ' . $delta),
                'base_two_surcharge_invoiced' => new Expression(
                    'base_two_surcharge_invoiced + ' . $baseDelta
                ),
            ],
            [
                $connection->quoteInto('entity_id = ?', $orderId),
                'two_surcharge_invoiced + ' . $delta . ' <= two_surcharge_amount',
                'base_two_surcharge_invoiced + ' . $baseDelta . ' <= base_two_surcharge_amount',
            ]
        );

        if ($strictAffected === 0) {
            // Cap would have been exceeded. Issue a clamped UPDATE so
            // the running total settles at exactly the cap, and log the
            // clamp. Don't throw — the customer's invoice has already
            // been persisted in the same transaction and rolling it
            // back over an internal accounting discrepancy would be
            // worse than the discrepancy itself. The merchant can
            // investigate via the log.
            $connection->update(
                $table,
                [
                    'two_surcharge_invoiced' => new Expression(
                        'LEAST(two_surcharge_invoiced + ' . $delta . ', two_surcharge_amount)'
                    ),
                    'base_two_surcharge_invoiced' => new Expression(
                        'LEAST(base_two_surcharge_invoiced + ' . $baseDelta . ', base_two_surcharge_amount)'
                    ),
                ],
                [$connection->quoteInto('entity_id = ?', $orderId)]
            );
            $this->logRepository->addLog(
                'Surcharge running total: cap clamped',
                [
                    'observer' => 'invoice',
                    'order_id' => $orderId,
                    'invoice_id' => $invoice->getId(),
                    'attempted_delta' => $amount,
                    'cap' => (float)$order->getTwoSurchargeAmount(),
                    'reason' => 'concurrent over-invoice attempt',
                ]
            );
        }

        // Keep the in-memory order instance loosely in sync so reads in
        // the same request see something plausible. Best-effort only —
        // concurrent invoice saves may have advanced the persisted total
        // beyond this view, and only the DB value is canonical.
        $order->setTwoSurchargeInvoiced((float)$order->getTwoSurchargeInvoiced() + $amount);
        $order->setBaseTwoSurchargeInvoiced((float)$order->getBaseTwoSurchargeInvoiced() + $baseAmount);
    }
}
