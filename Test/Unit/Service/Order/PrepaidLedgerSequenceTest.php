<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Service\Order;

use PHPUnit\Framework\TestCase;
use Two\Gateway\Service\Order\PrepaidLedger;

/**
 * Every sequence of shipments (or invoices), offline credit memos and online
 * refunds over a few orders, run against a simulator of Two's ledger
 * (TWO-26309). Each fulfilment and refund the plugin would send must meet
 * Two's rules:
 *
 * - a fulfilment: payable and prepaid not negative, invoice = payable +
 *   prepaid, and the unfulfilled rest of the order holding neither negative
 *   prepaid nor more prepaid than invoice;
 * - a refund: amount + prepaid = the refunded lines, each within what Two
 *   has fulfilled and not yet refunded of it.
 *
 * And the prepaid declared never runs past the tenders Magento still holds,
 * except where Two's own ledger leaves no other choice: a credit memo
 * refunded offline returns tenders Two never hears of.
 *
 * The oracle, at the end of every sequence Two can follow: what the buyer
 * owes Two, its fulfilled payable less what it refunded, is exactly the
 * shipped lines not refunded less the tenders Magento kept. A model that
 * declared too little prepaid would charge the buyer for what a tender paid.
 * Two cannot follow an offline credit memo that refunds a line it fulfilled,
 * or that pulls back tenders already declared to it as prepaid: Magento
 * returns tenders first on every memo, and an offline memo never reaches Two.
 *
 * Amounts are in cents. Magento returns tenders before the payment on every
 * credit memo, as Adobe Commerce does.
 */
class PrepaidLedgerSequenceTest extends TestCase
{
    /**
     * @dataProvider sequences
     * @param int[] $lines each line's gross
     * @param int $card the order's tenders
     * @param string[] $events "ship:<line>", "offline:<line>" or "online:<line>"
     */
    public function testEverySequenceMeetsTwosRules(array $lines, int $card, array $events, string $description): void
    {
        $two = ['invoice' => array_sum($lines), 'prepaid' => $card, 'fulfilled_invoice' => 0,
            'fulfilled_prepaid' => 0, 'refunded_prepaid' => 0, 'refunded_amount' => 0];
        $returned = 0;
        $state = array_fill(0, count($lines), 'open');
        $followable = true;
        foreach ($events as $event) {
            [$kind, $i] = explode(':', $event);
            $line = $lines[(int)$i];
            if ($kind === 'ship') {
                $kept = $card - $returned;
                $prepaid = PrepaidLedger::fulfilmentPrepaid($two, $kept, $line);
                $payable = $line - $prepaid;
                $prepaidLeft = $two['prepaid'] - $two['fulfilled_prepaid'] - $prepaid;
                $invoiceLeft = $two['invoice'] - $two['fulfilled_invoice'] - $line;
                $this->assertGreaterThanOrEqual(0, $payable, "$description, $event: payable");
                $this->assertGreaterThanOrEqual(0, $prepaid, "$description, $event: prepaid");
                $this->assertGreaterThanOrEqual(0, $prepaidLeft, "$description, $event: the rest keeps no negative prepaid");
                $this->assertGreaterThanOrEqual($prepaidLeft, $invoiceLeft, "$description, $event: the rest has invoice for its prepaid");
                $wanted = max(0, min($line, $kept - ($two['fulfilled_prepaid'] - $two['refunded_prepaid'])));
                $forced = max(0, ($two['prepaid'] - $two['fulfilled_prepaid']) - ($two['invoice'] - $two['fulfilled_invoice'] - $line));
                $this->assertLessThanOrEqual(max($wanted, $forced), $prepaid, "$description, $event: no more prepaid than kept, unless Two forces it");
                $two['fulfilled_invoice'] += $line;
                $two['fulfilled_prepaid'] += $prepaid;
                $state[(int)$i] = 'shipped';
                continue;
            }
            if ($kind === 'offline' && $state[(int)$i] === 'shipped') {
                $followable = false;
            }
            // A credit memo: Magento returns the tenders first.
            $back = min($card - $returned, $line);
            $returned += $back;
            if ($kind === 'offline' && $two['fulfilled_prepaid'] - $two['refunded_prepaid'] > $card - $returned) {
                $followable = false;
            }
            if ($kind === 'online') {
                $prepaid = PrepaidLedger::refundPrepaid($two, $back, $line);
                $amount = $line - $prepaid;
                $this->assertSame($line, $amount + $prepaid, "$description, $event: amount plus prepaid is the lines");
                $this->assertGreaterThanOrEqual(0, $amount, "$description, $event: amount");
                $this->assertLessThanOrEqual($two['fulfilled_prepaid'], $two['refunded_prepaid'] + $prepaid, "$description, $event: prepaid within fulfilled");
                $this->assertLessThanOrEqual(
                    $two['fulfilled_invoice'] - $two['fulfilled_prepaid'],
                    $two['refunded_amount'] + $amount,
                    "$description, $event: amount within fulfilled payable"
                );
                $two['refunded_prepaid'] += $prepaid;
                $two['refunded_amount'] += $amount;
            }
            $state[(int)$i] = 'refunded';
        }
        $this->assertLessThanOrEqual($two['prepaid'], $two['fulfilled_prepaid'], "$description: never past the order's prepaid");
        if ($followable) {
            $live = 0;
            foreach ($state as $i => $lineState) {
                $live += $lineState === 'shipped' ? $lines[$i] : 0;
            }
            $this->assertSame(
                $live - ($card - $returned),
                $two['fulfilled_invoice'] - $two['fulfilled_prepaid'] - $two['refunded_amount'],
                "$description: the buyer owes Two the live lines less the tenders kept"
            );
        }
    }

    public static function sequences(): array
    {
        $cases = [
            // The reported sequence: a memo refunded offline after the first shipment.
            '50+50, card 60: ship A, offline memo A, ship B' => [[5000, 5000], 6000, ['ship:0', 'offline:0', 'ship:1']],
        ];
        foreach ([[5000, 5000], [3000, 7000], [2500, 2500, 5000]] as $lines) {
            $total = array_sum($lines);
            foreach (array_unique([0, 2000, 6000, (int)($total / 2), $total]) as $card) {
                foreach (self::eventSequences(count($lines)) as $events) {
                    $cases[sprintf('%s, card %d: %s', implode('+', $lines), $card, implode(' ', $events))] = [$lines, $card, $events];
                }
            }
        }

        $named = [];
        foreach ($cases as $name => [$lines, $card, $events]) {
            $named[$name] = [$lines, $card, $events, $name];
        }

        return $named;
    }

    /**
     * Every valid order of events over $count lines: each line is shipped,
     * refunded offline before or after it ships, or shipped and refunded online.
     *
     * @return string[][]
     */
    private static function eventSequences(int $count): array
    {
        $fates = [['ship'], ['offline'], ['ship', 'offline'], ['ship', 'online']];
        $plans = [[]];
        for ($i = 0; $i < $count; $i++) {
            $next = [];
            foreach ($plans as $plan) {
                foreach ($fates as $fate) {
                    $next[] = array_merge($plan, [array_map(static fn ($kind) => "$kind:$i", $fate)]);
                }
            }
            $plans = $next;
        }
        $sequences = [];
        foreach ($plans as $plan) {
            foreach (self::interleavings($plan) as $sequence) {
                $sequences[implode(' ', $sequence)] = $sequence;
            }
        }

        return array_values($sequences);
    }

    /**
     * @param string[][] $queues each line's events, in their own order
     * @return string[][]
     */
    private static function interleavings(array $queues): array
    {
        $queues = array_values(array_filter($queues));
        if ($queues === []) {
            return [[]];
        }
        $out = [];
        foreach ($queues as $q => $queue) {
            $rest = $queues;
            $head = array_shift($rest[$q]);
            foreach (self::interleavings($rest) as $tail) {
                $out[] = array_merge([$head], $tail);
            }
        }

        return $out;
    }
}
