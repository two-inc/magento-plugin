<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * CI probe (TWO-26092), run inside a real Magento with the
 * Two_OrderPostprocessingFixture module enabled: proves the fixture's `after`
 * plugin is woven into the hook, fires for every request type, and that the
 * gates hold on what it returns. The context carries a real quote or order at
 * a Dutch address, so the shipping tax rate resolves and the re-split really
 * runs. Usage, from the Magento root:
 *   php <plugin>/Test/Integration/order-postprocessing-probe.php
 */
declare(strict_types=1);

use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\State;
use Two\Gateway\Api\OrderPostprocessingInterface as Hook;
use Two\Gateway\Service\Order\ComposeIntent;
use Two\Gateway\Service\Order\OrderPostprocessor;
use Two\Gateway\Test\Integration\ProbeFixtures;
use Two\OrderPostprocessingFixture\Plugin\Subscriber;

require getcwd() . '/app/bootstrap.php';
require __DIR__ . '/probe-fixtures.php';

$objectManager = Bootstrap::create(BP, $_SERVER)->getObjectManager();
$objectManager->get(State::class)->setAreaCode('frontend');
$postprocessor = $objectManager->get(OrderPostprocessor::class);
$quote = (new ProbeFixtures($objectManager))->quote([['probe-standard', 1]], false);
$subjects = [
    Hook::REQUEST_ORDER_INTENT => ['quote' => $quote],
    'order' => ['order' => $objectManager->get(ComposeIntent::class)->toOrder($quote)],
];

$line = static fn (string $type, string $net, string $tax, string $rate): array => [
    'order_item_id' => $type,
    'type' => $type,
    'net_amount' => $net,
    'tax_amount' => $tax,
    'gross_amount' => number_format((float)$net + (float)$tax, 2, '.', ''),
    'tax_rate' => $rate,
    'unit_price' => $net,
    'quantity' => 1,
];
$lines = [$line('PHYSICAL', '100.00', '21.00', '0.210000'), $line('SHIPPING_FEE', '29.00', '0.00', '0.000000')];
$order = ['net_amount' => '129.00', 'tax_amount' => '21.00', 'gross_amount' => '150.00', 'line_items' => $lines];
$payloads = [
    Hook::REQUEST_ORDER_INTENT => $order + ['currency' => 'EUR'],
    Hook::REQUEST_ORDER_CREATE => $order + ['currency' => 'EUR'],
    Hook::REQUEST_ORDER_UPDATE => $order + ['currency' => 'EUR'],
    Hook::REQUEST_ORDER_CONFIRM => [],
    Hook::REQUEST_CAPTURE => ['partial' => $order],
    Hook::REQUEST_REFUND => ['amount' => '150.00', 'currency' => 'EUR', 'line_items' => $lines],
    Hook::REQUEST_CANCEL => [],
];

$failures = [];
$check = static function (bool $ok, string $what) use (&$failures): void {
    echo ($ok ? 'ok   ' : 'FAIL ') . $what . PHP_EOL;
    if (!$ok) {
        $failures[] = $what;
    }
};
$run = static function (string $type, ?string $mode) use ($postprocessor, $payloads, $subjects) {
    Subscriber::$mode = $mode;
    $context = ['trigger' => 'ci_probe', 'endpoint' => 'probe'] + ($subjects[$type] ?? $subjects['order']);
    try {
        return $postprocessor->process($type, $payloads[$type], $context);
    } catch (\Magento\Framework\Exception\LocalizedException $e) {
        return $e->getMessage();
    } finally {
        Subscriber::$mode = null;
    }
};

// 29.00 of untaxed shipping re-split at the resolved 21%: net 23.97, tax 5.03, gross unchanged.
$resplit = static fn (string $prefix): array => [
    "$prefix/line_items/1/net_amount" => '23.97',
    "$prefix/line_items/1/tax_amount" => '5.03',
    "$prefix/line_items/1/gross_amount" => '29.00',
    "$prefix/line_items/1/tax_rate" => '0.210000',
    "$prefix/net_amount" => '123.97',
    "$prefix/tax_amount" => '26.03',
    "$prefix/gross_amount" => '150.00',
];

// [request type, mode, expectation, description]: an array is the payload sent, ['pointers' => ...] the
// values sent at those JSON pointers, and a string a code in the refusal.
$cases = [
    [Hook::REQUEST_ORDER_INTENT, null, $payloads[Hook::REQUEST_ORDER_INTENT], 'intent fires, unarmed payload unchanged'],
    [Hook::REQUEST_ORDER_CREATE, null, $payloads[Hook::REQUEST_ORDER_CREATE], 'create fires, unarmed payload unchanged'],
    [Hook::REQUEST_ORDER_UPDATE, null, $payloads[Hook::REQUEST_ORDER_UPDATE], 'update fires, unarmed payload unchanged'],
    [Hook::REQUEST_ORDER_CONFIRM, null, [], 'confirm fires with no body'],
    [Hook::REQUEST_CAPTURE, null, $payloads[Hook::REQUEST_CAPTURE], 'capture fires, unarmed payload unchanged'],
    [Hook::REQUEST_REFUND, null, $payloads[Hook::REQUEST_REFUND], 'refund fires, unarmed payload unchanged'],
    [Hook::REQUEST_CANCEL, null, [], 'cancel fires with no body'],
    [Hook::REQUEST_ORDER_INTENT, Subscriber::MODE_RESPLIT, ['pointers' => $resplit('')], 'intent re-split at the quote\'s shipping rate is sent'],
    [Hook::REQUEST_ORDER_CREATE, Subscriber::MODE_RESPLIT, ['pointers' => $resplit('')], 'create re-split at the order\'s shipping rate is sent'],
    [Hook::REQUEST_CAPTURE, Subscriber::MODE_RESPLIT, ['pointers' => $resplit('/partial')], 'partial capture re-split is sent'],
    [Hook::REQUEST_CAPTURE, Subscriber::MODE_GROSS_CHANGE, ['pointers' => ['/partial/gross_amount' => '151.00']], 'a gross change through the DI-bound totals helper is sent'],
    [Hook::REQUEST_CAPTURE, Subscriber::MODE_LINE_OFF, 'TWO_ORDER_POSTPROCESSING_LINE_INCONSISTENT', 'a broken line is refused by name'],
    [Hook::REQUEST_REFUND, Subscriber::MODE_THROW, 'TWO_ORDER_POSTPROCESSING_HOOK_FAILED', 'a throwing subscriber is refused by name'],
    [Hook::REQUEST_ORDER_UPDATE, Subscriber::MODE_RETURN_NON_ARRAY, 'TWO_ORDER_POSTPROCESSING_HOOK_FAILED', 'the interceptor\'s array return type catches a non-array'],
    [Hook::REQUEST_CANCEL, Subscriber::MODE_BODY_ON_BODYLESS, [], 'a body added to cancel is dropped, the cancel still sent'],
    [Hook::REQUEST_CANCEL, Subscriber::MODE_THROW, [], 'a throwing subscriber never blocks a cancel'],
    [Hook::REQUEST_ORDER_CONFIRM, Subscriber::MODE_THROW, [], 'a throwing subscriber never blocks a confirm'],
];

$at = static function ($payload, string $pointer) {
    foreach (array_slice(explode('/', $pointer), 1) as $key) {
        $payload = is_array($payload) ? ($payload[$key] ?? null) : null;
    }
    return $payload;
};

foreach ($cases as [$type, $mode, $expected, $description]) {
    $before = count(Subscriber::$calls);
    $result = $run($type, $mode);
    $check(count(Subscriber::$calls) === $before + 1 && end(Subscriber::$calls)['request_type'] === $type, $description . ': fired once as ' . $type);
    if (is_string($expected)) {
        $check(is_string($result) && str_contains($result, $expected), $description . ' (' . (is_string($result) ? $result : 'sent') . ')');
    } elseif (isset($expected['pointers'])) {
        foreach ($expected['pointers'] as $pointer => $value) {
            $check(is_array($result) && $at($result, $pointer) === $value, "$description: $pointer = $value (" . var_export(is_array($result) ? $at($result, $pointer) : $result, true) . ')');
        }
    } else {
        $check($result === $expected, $description . (is_string($result) ? " ($result)" : ''));
    }
}

$context = end(Subscriber::$calls)['context'];
$check(($context['contract_version'] ?? null) === 1 && array_key_exists('fallback_shipping_tax_rate', $context), 'the context carries the v1 keys');
$check(($context['shipping_tax_rate'] ?? null) === 0.21, 'the shipping tax rate resolves from the real subject (' . var_export($context['shipping_tax_rate'] ?? null, true) . ')');

if ($failures) {
    echo count($failures) . ' order postprocessing probe check(s) failed' . PHP_EOL;
    exit(1);
}
echo 'order postprocessing probe passed' . PHP_EOL;
