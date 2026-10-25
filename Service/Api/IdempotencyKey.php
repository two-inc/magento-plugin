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

    /** An order edit is named by what it sends. */
    public static function edit(string $twoOrderId, array $body): string
    {
        return self::build('edit', $twoOrderId, self::hash($body));
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
     * is named by its place among the order's saved records of that kind,
     * `<kind>-n<count + 1>`: the same until it is saved, as a retry after a
     * refusal or a failure saves nothing, and different from every record
     * saved before it.
     *
     * @param object|null $record a Magento model with getId()
     * @param iterable<object> $siblings the order's records of the same kind
     */
    public static function recordRef(string $kind, $record, iterable $siblings): string
    {
        $id = $record !== null ? (string)$record->getId() : '';
        if ($id !== '') {
            return $kind . '-' . $id;
        }
        $saved = 0;
        foreach ($siblings as $sibling) {
            if ((string)$sibling->getId() !== '') {
                $saved++;
            }
        }
        return $kind . '-n' . ($saved + 1);
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
