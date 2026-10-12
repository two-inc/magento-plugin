<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Service\Order;

use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Model\Order;
use Throwable;
use Two\Gateway\Api\BrandRegistryInterface;
use Two\Gateway\Service\Api\Adapter;

/**
 * The prepaid part a fulfilment or refund declares on an order with tenders
 * (TWO-26309), worked from Two's own record of the order rather than replayed
 * from Magento's history:
 *
 * - Two holds the order's invoice and prepaid amounts from the create, and
 *   the invoice and prepaid of every fulfilment and the prepaid of every
 *   refund it has accepted since. A credit memo refunded offline never
 *   reaches Two, so it moves none of those.
 * - Magento says what the buyer has really prepaid: the tenders, less what
 *   every credit memo returned to them, online or offline. A fulfilment
 *   wants to declare the part of that no earlier fulfilment has declared
 *   (net of prepaid refunded); a refund wants to declare what its memo
 *   returned to the tenders.
 * - Two's rules then bound it: a fulfilment's prepaid is at most its invoice
 *   and what Two's prepaid has left unfulfilled, and at least what keeps the
 *   unfulfilled rest from holding more prepaid than invoice; a refund's
 *   prepaid is at most its lines and the prepaid Two has fulfilled and not
 *   yet refunded, and at least what keeps its amount within the payable Two
 *   has fulfilled and not yet refunded.
 *
 * The remainder of an order Two has partly fulfilled is the root's figures
 * less its completed fulfilments: the plugin addresses the root, and Two
 * refuses an edit of a partly fulfilled root, so nothing the plugin sends can
 * move the remainder apart from them.
 *
 * Amounts are in cents.
 */
class PrepaidLedger
{
    /** Two's states for a fulfilment it counts as done. */
    private const COMPLETED = ['FULFILLING', 'FULFILLED', 'DELIVERED', 'REFUNDED'];

    private const READ_TIMEOUT_SECONDS = 10;

    /**
     * @var Adapter
     */
    private $adapter;

    /**
     * @var BrandRegistryInterface
     */
    private $brandRegistry;

    public function __construct(Adapter $adapter, BrandRegistryInterface $brandRegistry)
    {
        $this->adapter = $adapter;
        $this->brandRegistry = $brandRegistry;
    }

    /**
     * Two's record of the order.
     *
     * @param Order $order
     * @return array{invoice: int, prepaid: int, fulfilled_invoice: int, fulfilled_prepaid: int,
     *     refunded_prepaid: int, refunded_amount: int}
     * @throws LocalizedException when Two's record cannot be read: the prepaid part is never guessed
     */
    public function read(Order $order): array
    {
        $id = (string)$order->getTwoOrderId();
        $root = $this->get('/v1/order/' . $id, $order);
        $fulfilments = $this->get('/v1/order/' . $id . '/fulfillments', $order);
        $ledger = [
            'invoice' => self::cents($root['invoice_amount'] ?? $root['gross_amount'] ?? 0),
            'prepaid' => self::cents($root['prepaid_amount'] ?? 0),
            'fulfilled_invoice' => 0,
            'fulfilled_prepaid' => 0,
            'refunded_prepaid' => 0,
            'refunded_amount' => 0,
        ];
        foreach (is_array($fulfilments['fulfilled_orders'] ?? null) ? $fulfilments['fulfilled_orders'] : [] as $leaf) {
            if (is_array($leaf) && in_array($leaf['state'] ?? null, self::COMPLETED, true)) {
                $ledger['fulfilled_invoice'] += self::cents($leaf['invoice_amount'] ?? $leaf['gross_amount'] ?? 0);
                $ledger['fulfilled_prepaid'] += self::cents($leaf['prepaid_amount'] ?? 0);
            }
        }
        foreach (is_array($fulfilments['refunds'] ?? null) ? $fulfilments['refunds'] : [] as $refund) {
            if (is_array($refund)) {
                $ledger['refunded_prepaid'] += abs(self::cents($refund['prepaid_amount'] ?? 0));
                $ledger['refunded_amount'] += abs(self::cents($refund['amount'] ?? 0));
            }
        }

        return $ledger;
    }

    /**
     * A fulfilment's prepaid.
     *
     * @param array $ledger read()
     * @param int $kept the tenders Magento still holds as paid: placed, less what every memo returned
     * @param int $invoice the fulfilment's invoice, its lines
     * @return int
     */
    public static function fulfilmentPrepaid(array $ledger, int $kept, int $invoice): int
    {
        $wanted = max(0, min($invoice, $kept - ($ledger['fulfilled_prepaid'] - $ledger['refunded_prepaid'])));
        $prepaidLeft = max(0, $ledger['prepaid'] - $ledger['fulfilled_prepaid']);
        $invoiceLeft = $ledger['invoice'] - $ledger['fulfilled_invoice'];
        $least = max(0, $prepaidLeft - ($invoiceLeft - $invoice));
        $most = min($invoice, $prepaidLeft);

        return max(min($wanted, $most), min($least, $most));
    }

    /**
     * A refund's prepaid.
     *
     * @param array $ledger read()
     * @param int $returned what the memo returned to the tenders
     * @param int $lines the refund's lines
     * @return int
     */
    public static function refundPrepaid(array $ledger, int $returned, int $lines): int
    {
        $prepaidLeft = max(0, $ledger['fulfilled_prepaid'] - $ledger['refunded_prepaid']);
        $payableLeft = max(0, $ledger['fulfilled_invoice'] - $ledger['fulfilled_prepaid'] - ($ledger['refunded_amount'] ?? 0));
        $most = min($lines, $prepaidLeft);
        $least = max(0, $lines - $payableLeft);

        return max(min(max(0, $returned), $most), min($least, $most));
    }

    /**
     * @param mixed $amount
     * @return int
     */
    public static function cents($amount): int
    {
        return is_numeric($amount) ? (int)round((float)$amount * 100) : 0;
    }

    /**
     * @param string $endpoint
     * @param Order $order
     * @return array
     * @throws LocalizedException
     */
    private function get(string $endpoint, Order $order): array
    {
        try {
            $response = $this->adapter->execute(
                $endpoint,
                [],
                'GET',
                (int)$order->getStoreId(),
                null,
                null,
                self::READ_TIMEOUT_SECONDS
            );
        } catch (Throwable $e) {
            $response = null;
        }
        if (!is_array($response) || isset($response['error_code']) || isset($response['http_status'])) {
            throw new LocalizedException(__(
                'The prepaid part of this request could not be worked out: the order could not be read from %1. Try again.',
                $this->brandRegistry->getProductName()
            ));
        }

        return $response;
    }
}
