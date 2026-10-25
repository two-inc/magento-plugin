<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Service\Api;

use Magento\Framework\DataObject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Service\Api\IdempotencyKey;

/**
 * The key each operation sends as Idempotency-Key (TWO-26658): built from
 * durable ids, `<operation>_<ids>[_<short hash>]`, lowercase ASCII and at most
 * 100 characters.
 */
class IdempotencyKeyTest extends TestCase
{
    private const ORDER = 'b6f3c1a2-5d4e-4f60-9a7b-1c2d3e4f5a6b';

    #[DataProvider('keys')]
    public function testEachOperationHasItsOwnKeyShape(string $actual, string $expected, string $description): void
    {
        $this->assertSame($expected, $actual, $description);
        $this->assertLessThanOrEqual(IdempotencyKey::MAX_LENGTH, strlen($actual), $description . ': within the API limit');
        $this->assertMatchesRegularExpression('/^[a-z0-9_-]+$/', $actual, $description . ': lowercase ASCII');
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function keys(): array
    {
        $saved = static fn(int $id): DataObject => new DataObject(['id' => $id]);
        $unsaved = new DataObject();
        $o = self::ORDER;

        return [
            'create' => [IdempotencyKey::create('000000042', '1804289383'), 'create_000000042_1804289383', 'order create: the reserved order id and the attempt reference'],
            'create, next attempt' => [IdempotencyKey::create('000000042', '846930886'), 'create_000000042_846930886', 'a new attempt on the same reserved order id gets a new key'],
            'edit' => [IdempotencyKey::edit($o, ['gross_amount' => '10.00']), "edit_{$o}_" . substr(hash('sha256', '{"gross_amount":"10.00"}'), 0, 12), 'order edit: the order and a hash of the edit'],
            'confirm' => [IdempotencyKey::confirm($o), "confirm_$o", 'confirm: the order alone'],
            'whole fulfil' => [IdempotencyKey::fulfil($o), "fulfil_$o", 'whole fulfil: the order alone'],
            'partial fulfil, shipment' => [IdempotencyKey::fulfil($o, IdempotencyKey::recordRef('shipment', $saved(12), [])), "fulfil_{$o}_shipment-12", 'partial fulfil: the saved shipment'],
            'partial fulfil, unsaved invoice' => [IdempotencyKey::fulfil($o, IdempotencyKey::recordRef('invoice', $unsaved, [$saved(3), $unsaved])), "fulfil_{$o}_invoice-n2", 'partial fulfil: an unsaved invoice by its place after the saved ones'],
            'cancel' => [IdempotencyKey::cancel($o), "cancel_$o", 'cancel: the order alone'],
            'refund, unsaved credit memo' => [IdempotencyKey::refund($o, IdempotencyKey::recordRef('creditmemo', $unsaved, [$saved(5), $saved(6)])), "refund_{$o}_creditmemo-n3", 'refund: the credit memo by its place after the two saved ones'],
            'refund, saved credit memo' => [IdempotencyKey::refund($o, IdempotencyKey::recordRef('creditmemo', $saved(9), [$saved(5)])), "refund_{$o}_creditmemo-9", 'refund: a saved credit memo by its id'],
            'other write' => [IdempotencyKey::build('invoice_upload', 'INV/123 A', '1'), 'invoice_upload_inv-123-a_1', 'any other write: its ids folded to lowercase ASCII'],
        ];
    }

    public function testAKeyOverTheLimitEndsInAHashOfTheWhole(): void
    {
        $long = IdempotencyKey::build('fulfil', str_repeat('a', 150));
        $other = IdempotencyKey::build('fulfil', str_repeat('a', 149) . 'b');

        $this->assertSame(IdempotencyKey::MAX_LENGTH, strlen($long), 'capped at the API limit');
        $this->assertMatchesRegularExpression('/^fulfil_a+_[0-9a-f]{12}$/', $long, 'keeps its start and ends in a hash');
        $this->assertSame($long, IdempotencyKey::build('fulfil', str_repeat('a', 150)), 'the same ids give the same key');
        $this->assertNotSame($long, $other, 'ids differing past the cut still give different keys');
    }
}
