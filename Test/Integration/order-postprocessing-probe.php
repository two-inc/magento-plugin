<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * CI probe (TWO-26092), run inside a real Magento with the
 * Two_OrderPostprocessingFixture module enabled: proves the fixture's `after`
 * plugin is woven into the hook, fires for every request type, and that the
 * gates hold on what it returns. Usage, from the Magento root:
 *   php <plugin>/Test/Integration/order-postprocessing-probe.php
 */
declare(strict_types=1);

use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\State;
use Two\Gateway\Api\OrderPostprocessingInterface as Hook;
use Two\Gateway\Service\Order\OrderPostprocessor;
use Two\OrderPostprocessingFixture\Plugin\Subscriber;

require getcwd() . '/app/bootstrap.php';

$objectManager = Bootstrap::create(BP, $_SERVER)->getObjectManager();
$objectManager->get(State::class)->setAreaCode('frontend');
$postprocessor = $objectManager->get(OrderPostprocessor::class);

$line = static fn (string $type, string $net, string $tax, string $rate): array => [
    'order_item_id' => $type,
    'type' => $type,
    'net_amount' => $net,
    'tax_amount' => $tax,
    'gross_amount' => number_format((float)$net + (float)$tax, 2, '.', ''),
    'tax_rate' => $rate,
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
$run = static function (string $type, ?string $mode) use ($postprocessor, $payloads) {
    Subscriber::$mode = $mode;
    try {
        return $postprocessor->process($type, $payloads[$type], ['trigger' => 'ci_probe', 'endpoint' => 'probe']);
    } catch (\Magento\Framework\Exception\LocalizedException $e) {
        return $e->getMessage();
    } finally {
        Subscriber::$mode = null;
    }
};

// [request type, mode, expectation, description]: an array is the payload sent, a string a code in the refusal.
$cases = [
    [Hook::REQUEST_ORDER_INTENT, null, $payloads[Hook::REQUEST_ORDER_INTENT], 'intent fires, unarmed payload unchanged'],
    [Hook::REQUEST_ORDER_CREATE, null, $payloads[Hook::REQUEST_ORDER_CREATE], 'create fires, unarmed payload unchanged'],
    [Hook::REQUEST_ORDER_UPDATE, null, $payloads[Hook::REQUEST_ORDER_UPDATE], 'update fires, unarmed payload unchanged'],
    [Hook::REQUEST_ORDER_CONFIRM, null, [], 'confirm fires with no body'],
    [Hook::REQUEST_CAPTURE, null, $payloads[Hook::REQUEST_CAPTURE], 'capture fires, unarmed payload unchanged'],
    [Hook::REQUEST_REFUND, null, $payloads[Hook::REQUEST_REFUND], 'refund fires, unarmed payload unchanged'],
    [Hook::REQUEST_CANCEL, null, [], 'cancel fires with no body'],
    [Hook::REQUEST_CAPTURE, Subscriber::MODE_GROSS_CHANGE, '151.00', 'a gross change through the DI-bound totals helper is sent'],
    [Hook::REQUEST_CAPTURE, Subscriber::MODE_LINE_OFF, 'TWO_ORDER_POSTPROCESSING_LINE_INCONSISTENT', 'a broken line is refused by name'],
    [Hook::REQUEST_REFUND, Subscriber::MODE_THROW, 'TWO_ORDER_POSTPROCESSING_HOOK_FAILED', 'a throwing subscriber is refused by name'],
    [Hook::REQUEST_ORDER_UPDATE, Subscriber::MODE_RETURN_NON_ARRAY, 'TWO_ORDER_POSTPROCESSING_HOOK_FAILED', 'the interceptor\'s array return type catches a non-array'],
    [Hook::REQUEST_CANCEL, Subscriber::MODE_BODY_ON_BODYLESS, 'TWO_ORDER_POSTPROCESSING_BODY_NOT_ACCEPTED', 'a body on cancel is refused by name'],
];

foreach ($cases as [$type, $mode, $expected, $description]) {
    $before = count(Subscriber::$calls);
    $result = $run($type, $mode);
    $check(count(Subscriber::$calls) === $before + 1 && end(Subscriber::$calls)['request_type'] === $type, $description . ': fired once as ' . $type);
    if (is_array($expected)) {
        $check($result === $expected, $description);
    } elseif ($mode === Subscriber::MODE_GROSS_CHANGE) {
        $check(is_array($result) && ($result['partial']['gross_amount'] ?? null) === $expected, $description);
    } else {
        $check(is_string($result) && str_contains($result, $expected), $description . ' (' . (is_string($result) ? $result : 'sent') . ')');
    }
}

$context = end(Subscriber::$calls)['context'];
$check(($context['contract_version'] ?? null) === 1 && array_key_exists('shipping_tax_rate', $context), 'the context carries the v1 keys');

if ($failures) {
    echo count($failures) . ' order postprocessing probe check(s) failed' . PHP_EOL;
    exit(1);
}
echo 'order postprocessing probe passed' . PHP_EOL;
