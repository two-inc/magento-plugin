<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * CI probe (TWO-26092): order intent composed server-side from real quotes,
 * through core's real quote-to-order conversion, agrees with the quote's own
 * totals. Usage, from the Magento root:
 *   php <plugin>/Test/Integration/compose-intent-probe.php
 */
declare(strict_types=1);

use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\State;
use Magento\Quote\Model\Quote;
use Two\Gateway\Service\Order\ComposeIntent;
use Two\Gateway\Test\Integration\ProbeFixtures;

require getcwd() . '/app/bootstrap.php';
require __DIR__ . '/probe-fixtures.php';

$objectManager = Bootstrap::create(BP, $_SERVER)->getObjectManager();
$objectManager->get(State::class)->setAreaCode('frontend');
$fixtures = new ProbeFixtures($objectManager);
$composeIntent = $objectManager->get(ComposeIntent::class);

$failures = [];
$check = static function (bool $ok, string $what) use (&$failures): void {
    echo ($ok ? 'ok   ' : 'FAIL ') . $what . PHP_EOL;
    if (!$ok) {
        $failures[] = $what;
    }
};
$amount = static fn ($value): string => number_format((float)$value, 2, '.', '');

// [items [sku, qty], guest, coupon, tax config, description]
$cases = [
    [[['probe-standard', 2]], false, ProbeFixtures::COUPON, ProbeFixtures::EXCLUSIVE, 'coupon'],
    [[['probe-standard', 1], ['probe-reduced', 3]], false, null, ProbeFixtures::EXCLUSIVE, 'multi-rate'],
    [[['probe-standard', 1], ['probe-reduced', 1]], false, null, ProbeFixtures::INCLUSIVE, 'tax-inclusive prices and display'],
    [[['probe-standard', 1]], true, null, ProbeFixtures::EXCLUSIVE, 'guest'],
    [[['probe-configurable', 1]], false, null, ProbeFixtures::EXCLUSIVE, 'configurable'],
    [[['probe-bundle', 1]], false, null, ProbeFixtures::EXCLUSIVE, 'dynamic bundle of two rates'],
];

foreach ($cases as [$items, $guest, $coupon, $taxConfig, $description]) {
    $fixtures->configure($taxConfig);
    try {
        $quote = $fixtures->quote($items, $guest, $coupon);
        $intent = $composeIntent->execute($quote, ['company' => ['organization_number' => '123456789']]);
    } catch (\Throwable $e) {
        $check(false, "$description: composed (" . get_class($e) . ': ' . $e->getMessage() . ')');
        continue;
    }
    $address = $quote->getShippingAddress();
    $lines = $intent['line_items'];
    $shipping = array_values(array_filter($lines, static fn (array $l): bool => $l['type'] === 'SHIPPING_FEE'));
    $products = array_values(array_filter($lines, static fn (array $l): bool => $l['type'] !== 'SHIPPING_FEE'));
    // Items whose children are calculated (a dynamic bundle) carry their amounts on the children.
    $quoteItems = [];
    foreach ($quote->getAllVisibleItems() as $item) {
        $priced = $item->getHasChildren() && $item->isChildrenCalculated() ? $item->getChildren() : [$item];
        array_push($quoteItems, ...$priced);
    }

    // [actual, expected, what]
    $expectations = [
        [$intent['gross_amount'], $amount($quote->getGrandTotal()), 'gross = quote grand total'],
        // The old browser body added shipping_tax_amount to a tax total that already included it.
        [$intent['tax_amount'], $amount($address->getTaxAmount()), 'tax = quote tax total'],
        [$intent['net_amount'], $amount($quote->getGrandTotal() - $address->getTaxAmount()), 'net = gross - tax'],
        [$intent['currency'], (string)$quote->getQuoteCurrencyCode(), 'currency'],
        [count($shipping), 1, 'one shipping line'],
        [$shipping[0]['gross_amount'] ?? null, $amount($address->getShippingInclTax()), 'shipping gross = quote shipping incl. tax'],
        [$shipping[0]['net_amount'] ?? null, $amount($address->getShippingAmount()), 'shipping net = quote shipping'],
        [$shipping[0]['tax_amount'] ?? null, $amount($address->getShippingTaxAmount()), 'shipping tax = quote shipping tax'],
        [$shipping[0]['tax_rate'] ?? null, '0.210000', 'shipping rate'],
        [count($products), count($quoteItems), 'one line per priced quote item'],
    ];
    foreach ($quoteItems as $index => $item) {
        $net = $item->getRowTotal() - $item->getDiscountAmount() + $item->getDiscountTaxCompensationAmount();
        $expectations[] = [$products[$index]['net_amount'] ?? null, $amount($net), "{$item->getSku()} net = row total - discount"];
        $expectations[] = [$products[$index]['tax_amount'] ?? null, $amount($item->getTaxAmount()), "{$item->getSku()} tax = item tax"];
    }
    foreach (['gross_amount', 'net_amount', 'tax_amount'] as $field) {
        $expectations[] = [$amount(array_sum(array_column($lines, $field))), $intent[$field], "lines sum to $field"];
    }
    foreach ($expectations as [$actual, $expected, $what]) {
        $check($actual === $expected, "$description: $what (" . var_export($actual, true) . ' vs ' . var_export($expected, true) . ')');
    }
    foreach ($lines as $line) {
        $net = (float)$line['net_amount'];
        $implied = min(
            abs((float)$line['tax_amount'] - $net * (float)$line['tax_rate']),
            abs((float)$line['tax_amount'] - ($net + (float)$line['discount_amount']) * (float)$line['tax_rate'])
        );
        $check($implied <= 0.02, "$description: {$line['name']} tax reconciles with rate {$line['tax_rate']}");
    }
}
$fixtures->configure(ProbeFixtures::EXCLUSIVE);

if ($failures) {
    echo count($failures) . ' compose intent probe check(s) failed' . PHP_EOL;
    exit(1);
}
echo 'compose intent probe passed' . PHP_EOL;
