<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Service\Api;

/**
 * The Idempotency-Key every write request to the API carries (TWO-26658).
 *
 * A key names one logical operation: it is the same on every retry of that
 * operation (a network retry, a repeated event, an admin re-save) and
 * different for every other one. It is built from durable ids only, never
 * from time or randomness, as `<operation>_<ids>[_<short hash>]` in lowercase
 * ASCII, at most 100 characters (the API's limit).
 */
final class IdempotencyKey
{
    public const HEADER = 'Idempotency-Key';
    public const MAX_LENGTH = 100;
    private const HASH_LENGTH = 12;

    /**
     * One key per checkout attempt: the order's reserved increment id plus
     * the reference minted for this attempt, which the payload carries in
     * the merchant URLs and the order stores as two_order_reference. A buyer
     * who pays again gets a new reference, so a new key.
     */
    public static function create(string $incrementId, string $attemptReference): string
    {
        return self::build('create', $incrementId, $attemptReference);
    }

    /**
     * An order edit is chained on the key of the last edit Two accepted (the
     * create key before the first), plus what it sends: every edit in a
     * sequence gets its own key, even one returning to an earlier state,
     * while a retry of an edit repeats its key.
     */
    public static function edit(string $twoOrderId, string $previousKey, array $body): string
    {
        return self::build('edit', $twoOrderId, self::hash([$previousKey, $body]));
    }

    public static function confirm(string $twoOrderId): string
    {
        return self::build('confirm', $twoOrderId);
    }

    /**
     * The whole order with no record, a partial fulfilment with the shop's
     * own record for it (see self::recordRef()).
     */
    public static function fulfil(string $twoOrderId, ?string $recordRef = null): string
    {
        return $recordRef === null
            ? self::build('fulfil', $twoOrderId)
            : self::build('fulfil', $twoOrderId, $recordRef);
    }

    public static function cancel(string $twoOrderId): string
    {
        return self::build('cancel', $twoOrderId);
    }

    public static function refund(string $twoOrderId, string $recordRef): string
    {
        return self::build('refund', $twoOrderId, $recordRef);
    }

    /**
     * A shop record (credit memo, invoice) as `<kind>-<entity id>`. Magento
     * calls the payment method before it saves the record, so an unsaved one
     * is named by its place among the order's saved records of that kind plus
     * a hash of what it sends, `<kind>-n<count + 1>-<hash>`: the same on a
     * retry, which saves nothing, and different for a record saved before it
     * or one with other amounts or lines in the same place.
     *
     * @param object|null $record a Magento model with getId()
     * @param iterable<object> $siblings the order's records of the same kind
     * @param array $content the composed request for the record
     */
    public static function recordRef(string $kind, $record, iterable $siblings, array $content): string
    {
        $id = $record !== null ? (string)$record->getId() : '';
        if ($id !== '') {
            return $kind . '-' . $id;
        }
        return $kind . '-n' . (self::savedCount($siblings) + 1) . '-' . self::hash($content);
    }

    /**
     * @param iterable<object> $records
     */
    public static function savedCount(iterable $records): int
    {
        $saved = 0;
        foreach ($records as $record) {
            if ((string)$record->getId() !== '') {
                $saved++;
            }
        }
        return $saved;
    }

    /**
     * Whether Two's refunded total rose by at least what this refund sent.
     * Two answers a refund with the order's refunded total, so a replay of
     * an earlier refund under this key leaves it short of that rise. A
     * larger rise is a refund made at the same time or outside Magento, and
     * this one still happened. Null when the total before it is unknown (an
     * order refunded before the total was recorded), which cannot be checked.
     */
    public static function refundApplied(?float $totalBefore, float $totalAfter, float $sent): ?bool
    {
        if ($totalBefore === null) {
            return null;
        }
        return abs($totalAfter) - abs($totalBefore) >= abs($sent) - 0.005;
    }

    /**
     * Whether the fulfilment Two answered with is new to the order: a replay
     * of an earlier partial fulfil names a fulfilled order one of the
     * order's saved invoices already records.
     *
     * @param iterable<object> $invoices the order's invoices, with getId() and getTransactionId()
     */
    public static function fulfilmentIsNew(string $fulfilledOrderId, iterable $invoices): bool
    {
        foreach ($invoices as $invoice) {
            if ((string)$invoice->getId() !== '' && (string)$invoice->getTransactionId() === $fulfilledOrderId) {
                return false;
            }
        }
        return true;
    }

    /**
     * `<operation>_<part>_<part>...`, each part folded to [a-z0-9-]. A key
     * over the limit keeps its start and ends in a hash of the whole.
     */
    public static function build(string $operation, string ...$parts): string
    {
        $key = strtolower($operation);
        foreach ($parts as $part) {
            $key .= '_' . trim((string)preg_replace('/[^a-z0-9-]+/', '-', strtolower($part)), '-');
        }
        if (strlen($key) > self::MAX_LENGTH) {
            $key = substr($key, 0, self::MAX_LENGTH - self::HASH_LENGTH - 1) . '_' . self::hash($key);
        }
        return $key;
    }

    /**
     * A short hash of a string, or of an array as the JSON it is sent as.
     *
     * @param string|array $value
     */
    public static function hash($value): string
    {
        return substr(hash('sha256', is_string($value) ? $value : (string)json_encode($value)), 0, self::HASH_LENGTH);
    }
}
