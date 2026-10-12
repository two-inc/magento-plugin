<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Service\Order;

use Magento\Sales\Model\Order;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Test\Unit\Service\Order\Doubles\TenderOrderFixtures;

/**
 * Every body the builders send, run through the amount rules Two's API applies
 * (TWO-26309), with and without tenders. The order: one product, 100.00 net at
 * 21% (121.00), and 29.00 of untaxed shipping: 150.00 of lines, 21.00 of tax.
 * A tender (Adobe Commerce's gift card or store credit) pays part of it, so
 * Magento's grand total is the lines less the tender.
 */
class TwoAmountRulesContractTest extends TestCase
{
    use TenderOrderFixtures;

    /**
     * @dataProvider bodies
     * @param string $build create, edit, capture, shipment or refund
     * @param array<string, float> $tenders Adobe Commerce tender field => amount, on the order and the document
     * @param string|null $prepaid the prepaid_amount the body declares; null for none
     */
    public function testEveryBodyMeetsTheOrderAmountRules(string $build, array $tenders, ?string $prepaid, string $description): void
    {
        $this->memos = [];
        $this->shipments = [];
        $order = $this->order($tenders);
        $body = $this->{$build}($order, $tenders);
        if (isset($body['partial'])) {
            $body = $body['partial'];
        }

        $this->assertSame([], self::ruleFailures($body, $build, self::sum($tenders)), $description . "\n" . json_encode(array_diff_key($body, ["merchant_urls" => 1, "buyer" => 1, "billing_address" => 1, "shipping_address" => 1, "terms" => 1]), JSON_PRETTY_PRINT));
        $this->assertSame($prepaid, $body['prepaid_amount'] ?? null, $description . ': prepaid');
        if ($prepaid === null) {
            $this->assertSame([], array_intersect(['invoice_amount', 'payable_amount'], array_keys($body)), $description . ': no prepaid fields');
        }
    }

    public static function bodies(): array
    {
        $card = ['gift_cards_amount' => 10.00];
        $credit = ['customer_balance_amount' => 25.00];
        $cover = ['gift_cards_amount' => 150.00];

        return [
            ['create', [], null, 'no tender: sent as before'],
            ['edit', [], null, 'no tender: an edit as before'],
            ['capture', [], null, 'no tender: a partial capture as before'],
            ['refund', [], null, 'no tender: a refund is its lines'],
            ['create', $card, '10.00', 'a gift card is declared as prepaid'],
            ['edit', $credit, '25.00', 'store credit on an edit'],
            ['create', $cover, '150.00', 'a gift card covering the whole order leaves nothing payable'],
            ['capture', $card, '10.00', 'a partial capture carries the invoice\'s share of the tenders'],
            ['shipment', $credit, '25.00', 'the first partial shipment takes the tenders'],
            ['refund', $card, '10.00', 'a refund returning a gift card: the lines beyond amount are prepaid'],
            ['shipment', ['gift_cards_amount' => 100.00], '89.50', 'a large card: the first shipment is wholly prepaid'],
            ['secondShipment', ['gift_cards_amount' => 100.00], '10.50', 'the second shipment takes what the card has left'],
            ['secondShipment', $card, '0.00', 'the first shipment used the card up'],
            ['shipmentAfterMemo', ['gift_cards_amount' => 30.00], '0.00', 'a memo returned the card before the shipment'],
            ['netOfRefunds', ['gift_cards_amount' => 30.00], '0.00', 'net of refunds: the memo returned the card, so the buyer owes all of it'],
            ['netOfRefunds', ['gift_cards_amount' => 100.00], '39.50', 'net of refunds: what the memo did not return stays prepaid'],
        ];
    }

    /**
     * An order placed with Two before tenders were declared (TWO-26309): Two
     * holds no prepaid and an invoice of the grand total alone. Every request
     * on it is sent exactly as it was then: lines reconciled against the
     * grand total, totals from the grand total, no prepaid fields. An order
     * placed since declares its tenders on the same requests.
     *
     * @dataProvider placedOrders
     */
    public function testAnOrderPlacedBeforeTendersWereDeclaredIsSentAsBefore(string $build, bool $legacy): void
    {
        $card = ['gift_cards_amount' => 10.00];
        $this->memos = [];
        $this->shipments = [];
        $order = $this->order($card);
        if ($legacy) {
            $this->ledger['prepaid'] = 0;
            $this->ledger['invoice'] = 14000;
        }
        if ($build === 'refund') {
            $this->ledger['fulfilled_invoice'] = $this->ledger['invoice'];
            $this->ledger['fulfilled_prepaid'] = $this->ledger['prepaid'];
        }
        $body = $this->{$build}($order, $card);
        $block = $body['partial'] ?? $body;

        if (!$legacy) {
            $this->assertArrayHasKey('prepaid_amount', $block, "$build: a new order declares its prepaid part");
            return;
        }
        $this->assertSame([], array_intersect(['invoice_amount', 'payable_amount', 'prepaid_amount'], array_keys($block)), "$build: no prepaid fields");
        $expected = [
            'edit' => ['gross_amount' => '140.00', 'net_amount' => '119.00', 'tax_amount' => '21.00'],
            'capture' => ['gross_amount' => '140.00', 'net_amount' => '119.00', 'tax_amount' => '21.00'],
            'shipment' => ['gross_amount' => '89.50', 'net_amount' => '79.00', 'tax_amount' => '10.50'],
            'refund' => ['amount' => '111.00'],
        ][$build];
        $this->assertSame($expected, array_intersect_key($block, $expected), "$build: totals as before");
        $this->assertSame(
            [],
            array_values(array_filter($block['line_items'], static fn ($l) => ($l['order_item_id'] ?? '') === 'other_charges')),
            "$build: no residual line"
        );
    }

    public static function placedOrders(): array
    {
        $cases = [];
        foreach (['edit', 'capture', 'shipment', 'refund'] as $build) {
            $cases["$build, placed before"] = [$build, true];
            $cases["$build, placed since"] = [$build, false];
        }

        return $cases;
    }

    /**
     * Several refunds of one order: each is its lines, and together they
     * return no more prepaid than the order declared.
     */
    public function testSeveralRefundsReturnNoMoreThanTheOrderPrepaid(): void
    {
        $card = ['gift_cards_amount' => 30.00];
        $order = $this->order($card);
        $this->fulfilled(15000, 3000);
        $bodies = [$this->refundOneUnit($order, $card)];
        $this->ledger['refunded_prepaid'] += 3000;
        $bodies[] = $this->refundOneUnit($order, []);
        $returned = 0;
        foreach ($bodies as $i => $body) {
            $this->assertSame([], self::ruleFailures($body, 'refund', 30.00), "refund $i");
            $returned += (int)round((float)($body['prepaid_amount'] ?? 0) * 100);
        }
        $this->assertSame(['30.00', '0.00'], [$bodies[0]['prepaid_amount'] ?? null, $bodies[1]['prepaid_amount'] ?? null]);
        $this->assertLessThanOrEqual(3000, $returned, 'no more prepaid back than the order declared');
    }

    /**
     * An unreadable stored tax code map (TWO-26309): a create refuses, while
     * a capture and a refund still go, their 0% shipping line uncoded.
     *
     * @dataProvider unreadableMapBuilds
     */
    public function testAnUnreadableMapRefusesACreateButNeverAFulfilmentOrRefund(string $build, bool $refused): void
    {
        $this->unreadableMap = true;
        $order = $this->order([]);
        if ($refused) {
            $this->expectException(\Magento\Framework\Exception\LocalizedException::class);
        }
        $body = $this->{$build}($order, []);
        $lines = $body['line_items'] ?? $body['partial']['line_items'] ?? [];
        $shipping = array_values(array_filter($lines, static fn ($l) => ($l['type'] ?? '') === 'SHIPPING_FEE'));
        $this->assertCount(1, $shipping, $build . ': the 0% shipping line went');
        $this->assertArrayNotHasKey('tax_code', $shipping[0], $build . ': uncoded');
    }

    public static function unreadableMapBuilds(): array
    {
        return ['create' => ['create', true], 'capture' => ['capture', false], 'refund' => ['refund', false]];
    }

    /** The intent's net carries the tenders its lines carry, so it agrees with them (TWO-26309). */
    public function testTheIntentNetIncludesTheTenders(): void
    {
        $body = $this->intent($this->order(['gift_cards_amount' => 10.00]));

        $this->assertSame(['140.00', '129.00', '21.00'], [$body['gross_amount'], $body['net_amount'], $body['tax_amount']]);
        $this->assertSame('129.00', number_format(array_sum(array_column($body['line_items'], 'net_amount')), 2, '.', ''));
    }

    /**
     * A port of the rules Two's API applies to an order body (create, edit,
     * partial fulfilment) or a refund. Amounts are compared in cents.
     *
     * @param array $body
     * @param string $build
     * @param float $orderPrepaid the order's tenders: the most a fulfilment or refund can declare
     * @return string[] the rules the body breaks
     */
    private static function ruleFailures(array $body, string $build, float $orderPrepaid): array
    {
        $c = static fn ($amount): int => (int)round((float)$amount * 100);
        $lines = $body['line_items'] ?? [];
        $sum = static fn (string $field): int => array_sum(array_map(static fn ($l) => $c($l[$field] ?? 0), $lines));
        $prepaid = $c($body['prepaid_amount'] ?? 0);
        $failures = [];
        foreach ($lines as $i => $line) {
            if ($c($line['gross_amount']) !== $c($line['net_amount']) + $c($line['tax_amount'])) {
                $failures[] = "line $i gross is net plus tax";
            }
            if (abs($c($line['tax_amount']) - (int)round((float)$line['net_amount'] * (float)$line['tax_rate'] * 100)) > 2) {
                $failures[] = "line $i tax is its rate on net, within 0.02";
            }
        }
        if ($build === 'refund') {
            if (abs($c($body['amount'])) + abs($prepaid) !== abs($sum('gross_amount'))) {
                $failures[] = 'amount plus prepaid is the refunded lines';
            }
            if (abs($prepaid) > $c($orderPrepaid)) {
                $failures[] = 'prepaid within the order\'s';
            }
            if (isset($body['tax_subtotals'])) {
                $subtotals = array_sum(array_map(static fn ($s) => $c($s['taxable_amount']) + $c($s['tax_amount']), $body['tax_subtotals']));
                if (abs($subtotals - abs($c($body['amount']) + $prepaid)) > 100) {
                    $failures[] = 'tax subtotals within 1 of amount plus prepaid';
                }
            }

            return $failures;
        }
        $hasInvoice = array_key_exists('invoice_amount', $body);
        if ($hasInvoice !== array_key_exists('payable_amount', $body)) {
            $failures[] = 'invoice_amount and payable_amount come together';
        }
        $payable = $c($body['payable_amount'] ?? $body['gross_amount']);
        $invoice = $hasInvoice ? $c($body['invoice_amount']) : $payable + $prepaid;
        $net = $c($body['net_amount']);
        $tax = $c($body['tax_amount']);
        $rules = [
            'gross, when sent, is payable' => isset($body['gross_amount']) && $c($body['gross_amount']) !== $payable,
            'invoice is payable plus prepaid' => $invoice !== $payable + $prepaid,
            'net within 0.02 of the lines' => abs($sum('net_amount') - $net) > 2,
            'tax within 0.99 of the lines' => abs($sum('tax_amount') - $tax) > 99,
            'invoice is net plus tax' => $invoice !== $net + $tax,
            'payable is not negative' => $payable < 0,
            'tax subtotals sum to the tax' => isset($body['tax_subtotals']) && array_sum(array_map(
                static fn ($s) => $c($s['tax_amount']),
                $body['tax_subtotals']
            )) !== $tax,
        ];
        if (in_array($build, ['capture', 'shipment', 'secondShipment'], true)) {
            // A partial fulfilment must leave the rest of the order no more prepaid than invoice.
            [$priorInvoice, $priorPrepaid] = $build === 'secondShipment' ? [8950, min($c($orderPrepaid), 8950)] : [0, 0];
            $rules['the remainder keeps prepaid within its invoice'] =
                (15000 - $priorInvoice - $invoice) - ($c($orderPrepaid) - $priorPrepaid - $prepaid) < 0;
            $rules['prepaid within what the order has left'] = $prepaid > $c($orderPrepaid) - $priorPrepaid;
        }

        return array_merge($failures, array_keys(array_filter($rules)));
    }
}
