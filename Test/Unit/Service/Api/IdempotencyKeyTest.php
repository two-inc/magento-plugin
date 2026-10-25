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
        $lines = ['amount' => '10.00', 'line_items' => [['name' => 'Widget']]];
        $linesHash = substr(hash('sha256', (string)json_encode($lines)), 0, 12);

        return [
            'create' => [IdempotencyKey::create('000000042', '1804289383'), 'create_000000042_1804289383', 'order create: the reserved order id and the attempt reference'],
            'create, next attempt' => [IdempotencyKey::create('000000042', '846930886'), 'create_000000042_846930886', 'a new attempt on the same reserved order id gets a new key'],
            'edit' => [IdempotencyKey::edit($o, 'create_1_ab', ['gross_amount' => '10.00']), "edit_{$o}_" . substr(hash('sha256', '["create_1_ab",{"gross_amount":"10.00"}]'), 0, 12), 'order edit: the order and a hash of the previous key and the edit'],
            'confirm' => [IdempotencyKey::confirm($o), "confirm_$o", 'confirm: the order alone'],
            'whole fulfil' => [IdempotencyKey::fulfil($o), "fulfil_$o", 'whole fulfil: the order alone'],
            'partial fulfil, shipment' => [IdempotencyKey::fulfil($o, IdempotencyKey::recordRef('shipment', $saved(12), [], ['x'])), "fulfil_{$o}_shipment-12", 'partial fulfil: the saved shipment'],
            'partial fulfil, unsaved invoice' => [IdempotencyKey::fulfil($o, IdempotencyKey::recordRef('invoice', $unsaved, [$saved(3), $unsaved], $lines)), "fulfil_{$o}_invoice-n2-$linesHash", 'partial fulfil: an unsaved invoice by its place after the saved ones and its lines'],
            'cancel' => [IdempotencyKey::cancel($o), "cancel_$o", 'cancel: the order alone'],
            'refund, unsaved credit memo' => [IdempotencyKey::refund($o, IdempotencyKey::recordRef('creditmemo', $unsaved, [$saved(5), $saved(6)], $lines)), "refund_{$o}_creditmemo-n3-$linesHash", 'refund: the credit memo by its place after the two saved ones and its amount and lines'],
            'refund, saved credit memo' => [IdempotencyKey::refund($o, IdempotencyKey::recordRef('creditmemo', $saved(9), [$saved(5)], $lines)), "refund_{$o}_creditmemo-9", 'refund: a saved credit memo by its id'],
            'other write' => [IdempotencyKey::build('invoice_upload', 'INV/123 A', '1'), 'invoice_upload_inv-123-a_1', 'any other write: its ids folded to lowercase ASCII'],
        ];
    }

    /**
     * Edits A, B, A, B each get their own key, and a retry of one repeats it.
     */
    public function testEditsAreChainedOnThePreviousKey(): void
    {
        $a = ['gross_amount' => '10.00'];
        $b = ['gross_amount' => '12.00'];
        $first = IdempotencyKey::edit(self::ORDER, 'create_1_ab', $a);
        $second = IdempotencyKey::edit(self::ORDER, $first, $b);
        $third = IdempotencyKey::edit(self::ORDER, $second, $a);
        $fourth = IdempotencyKey::edit(self::ORDER, $third, $b);

        $this->assertCount(4, array_unique([$first, $second, $third, $fourth]), 'A, B, A, B are four operations');
        $this->assertSame($first, IdempotencyKey::edit(self::ORDER, 'create_1_ab', $a), 'a retry repeats its key');
    }

    /**
     * Two answers a refund with the order's refunded total, which moves by
     * the amount sent unless Two replayed an earlier refund.
     */
    #[DataProvider('refundAnswers')]
    public function testARefundAnswerIsCheckedAgainstWhatWasSent(?float $before, float $after, float $sent, ?bool $expected, string $description): void
    {
        $this->assertSame($expected, IdempotencyKey::refundApplied($before, $after, $sent), $description);
    }

    /**
     * @return array<string, array{0: float|null, 1: float, 2: float, 3: bool|null, 4: string}>
     */
    public static function refundAnswers(): array
    {
        return [
            'first refund' => [0.0, 40.0, 40.0, true, 'the total moved by the amount sent'],
            'second refund' => [40.0, 65.5, 25.5, true, 'the total is cumulative across refunds'],
            'replayed' => [40.0, 40.0, 25.5, false, 'a total that did not move is a replay'],
            'replayed, different amount' => [0.0, 40.0, 30.0, false, 'a total that moved by another amount is a replay'],
            'negative totals' => [-40.0, -65.5, 25.5, true, 'the sign Two answers with does not matter'],
            'unknown before' => [null, 65.5, 25.5, null, 'with no recorded total there is nothing to check'],
        ];
    }

    /**
     * A replayed partial fulfil names a fulfilled order a saved invoice
     * already records; a new one names one none does.
     */
    #[DataProvider('fulfilAnswers')]
    public function testAFulfilAnswerIsCheckedAgainstTheSavedInvoices(string $fulfilled, bool $expected, string $description): void
    {
        $invoices = [
            new DataObject(['id' => 3, 'transactionId' => 'fulfilled-1']),
            new DataObject(['transactionId' => 'fulfilled-2']),
        ];
        $this->assertSame($expected, IdempotencyKey::fulfilmentIsNew($fulfilled, $invoices), $description);
    }

    /**
     * @return array<string, array{0: string, 1: bool, 2: string}>
     */
    public static function fulfilAnswers(): array
    {
        return [
            'new' => ['fulfilled-3', true, 'a fulfilled order no invoice records is new'],
            'replayed' => ['fulfilled-1', false, 'a fulfilled order a saved invoice records is a replay'],
            'unsaved invoice' => ['fulfilled-2', true, 'only saved invoices count'],
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
