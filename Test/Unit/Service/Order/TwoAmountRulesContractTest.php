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
        $order = $this->order($tenders);
        $body = $this->{$build}($order, $tenders);

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
        ];
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
        if (in_array($build, ['capture', 'shipment'], true)) {
            // A partial fulfilment must leave the rest of the order no more prepaid than invoice.
            $rules['the remainder keeps prepaid within its invoice'] = (15000 - $invoice) - ($c($orderPrepaid) - $prepaid) < 0;
            $rules['prepaid within the order\'s'] = $prepaid > $c($orderPrepaid);
        }

        return array_merge($failures, array_keys(array_filter($rules)));
    }
}
