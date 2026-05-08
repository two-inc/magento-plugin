<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace ABN\Gateway\Observer;

use ABN\Gateway\Api\Log\RepositoryInterface as LogRepository;
use Magento\Framework\DB\Sql\Expression;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
/**
 * Bumps `order.two_surcharge_refunded` once per creditmemo creation. See
 * InvoiceSurchargeRunningTotal for the rationale on doing this in an
 * observer rather than the collector, on using a direct UPDATE rather
 * than the order repository, on the additive SQL expression rather
 * than read-modify-write (ABN-384 lost-update race), and on the
 * cap-guarded WHERE plus LEAST-clamped fallback (ABN-385 over-refund
 * race).
 */
class CreditmemoSurchargeRunningTotal implements ObserverInterface
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
        $creditmemo = $observer->getEvent()->getCreditmemo();
        if (!$creditmemo) {
            return;
        }
        if ($creditmemo->getOrigData('entity_id') !== null) {
            return;
        }

        $amount = (float)$creditmemo->getTwoSurchargeAmount();
        if ($amount <= 0) {
            return;
        }
        $baseAmount = (float)$creditmemo->getBaseTwoSurchargeAmount();

        $order = $creditmemo->getOrder();
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
        // SET — closes the lost-update race (ABN-384). `column + delta`
        // is atomic at the InnoDB row-lock level: lock, read, compute,
        // write, release in one shot. Concurrent writers serialize
        // through the lock and compose correctly (A bumps to X+a, then
        // B reads X+a and bumps to X+a+b). NOT a CAS / optimistic-lock
        // pattern — a true CAS would read a baseline into PHP and gate
        // the UPDATE on `WHERE column = $baseline` with retry-on-
        // mismatch. Additive arithmetic has no contended baseline so we
        // don't need that.
        //
        // WHERE — closes the cap-violation race (ABN-385). Even with
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
        //
        // The refund-side analogue of `Observer/InvoiceSurchargeRunningTotal`
        // — invariants are identical modulo column name (`refunded`
        // vs `invoiced`).
        $strictAffected = $connection->update(
            $table,
            [
                'two_surcharge_refunded' => new Expression('two_surcharge_refunded + ' . $delta),
                'base_two_surcharge_refunded' => new Expression(
                    'base_two_surcharge_refunded + ' . $baseDelta
                ),
            ],
            [
                $connection->quoteInto('entity_id = ?', $orderId),
                'two_surcharge_refunded + ' . $delta . ' <= two_surcharge_amount',
                'base_two_surcharge_refunded + ' . $baseDelta . ' <= base_two_surcharge_amount',
            ]
        );

        if ($strictAffected === 0) {
            $connection->update(
                $table,
                [
                    'two_surcharge_refunded' => new Expression(
                        'LEAST(two_surcharge_refunded + ' . $delta . ', two_surcharge_amount)'
                    ),
                    'base_two_surcharge_refunded' => new Expression(
                        'LEAST(base_two_surcharge_refunded + ' . $baseDelta . ', base_two_surcharge_amount)'
                    ),
                ],
                [$connection->quoteInto('entity_id = ?', $orderId)]
            );
            $this->logRepository->addLog(
                'Surcharge running total: cap clamped',
                [
                    'observer' => 'creditmemo',
                    'order_id' => $orderId,
                    'creditmemo_id' => $creditmemo->getId(),
                    'attempted_delta' => $amount,
                    'cap' => (float)$order->getTwoSurchargeAmount(),
                    'reason' => 'concurrent over-refund attempt',
                ]
            );
        }

        // Best-effort in-memory sync. See InvoiceSurchargeRunningTotal
        // for why this is optimistic, not authoritative.
        $order->setTwoSurchargeRefunded((float)$order->getTwoSurchargeRefunded() + $amount);
        $order->setBaseTwoSurchargeRefunded((float)$order->getBaseTwoSurchargeRefunded() + $baseAmount);
    }
}
