<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * CI probe (TWO-26276), run inside a real Magento: the postprocessing hook's
 * default handler owns the shop-match checks and stands down for any other
 * handler, which the interception config detects at the request's own area.
 *
 * Usage, from the Magento root, once per area:
 *   php <plugin>/Test/Integration/shop-match-probe.php <none|fixture> <area> [detect]
 * CI runs every area in full; "detect" is for a quick local check.
 *
 * "none" runs before Two_OrderPostprocessingFixture is enabled, "fixture"
 * after. The fixture disables its plugin in crontab, so there the default
 * handler is back. "detect" checks detection only, without building orders.
 *
 * The orders are real conversions of real quotes, composed by the real
 * builder. The shipping tax fallback's case is written on each as placement
 * records it, with 29.00 of shipping charged untaxed (refused at 21%) or
 * at 6.09 (reconciles): Magento records a rate for the probe shop's shipping
 * itself, so the fallback would otherwise never apply.
 */
declare(strict_types=1);

use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\State;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\ObjectManager\ConfigLoaderInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Invoice;
use Two\Gateway\Api\OrderPostprocessingInterface as Hook;
use Two\Gateway\Exception\ShopMatchRefusedException;
use Two\Gateway\Service\Order\ComposeCapture;
use Two\Gateway\Service\Order\ComposeIntent;
use Two\Gateway\Service\Order\ComposeOrder;
use Two\Gateway\Service\Order\OrderPostprocessor;
use Two\Gateway\Service\Order\PostprocessingSubscribers;
use Two\Gateway\Test\Integration\ProbeFixtures;

require getcwd() . '/app/bootstrap.php';
require __DIR__ . '/probe-fixtures.php';

[$expect, $area, $detectOnly] = [$argv[1] ?? 'none', $argv[2] ?? 'frontend', ($argv[3] ?? '') === 'detect'];
const FIXTURE = 'two_order_postprocessing_fixture';
const FIXTURE_CLASS = 'Two\OrderPostprocessingFixture\Plugin\Subscriber';
const BUYER_REFUSAL = 'This order could not be placed. Please contact the merchant.';
const MERCHANT_REFUSAL = 'Shipping tax on this order does not match the rate of the Tax Class for Shipping';

$objectManager = Bootstrap::create(BP, $_SERVER)->getObjectManager();
$objectManager->get(State::class)->setAreaCode($area);
$objectManager->configure($objectManager->get(ConfigLoaderInterface::class)->load($area));

$failures = [];
$check = static function (bool $ok, string $what) use (&$failures, $expect, $area): void {
    echo ($ok ? 'ok   ' : 'FAIL ') . "[$expect/$area] $what" . PHP_EOL;
    if (!$ok) {
        $failures[] = $what;
    }
};
$finish = static function () use (&$failures): void {
    if ($failures) {
        echo count($failures) . ' shop-match probe check(s) failed' . PHP_EOL;
        exit(1);
    }
    echo 'shop-match probe passed' . PHP_EOL;
    exit(0);
};

// Detection, from the interceptor the postprocessor itself calls.
$fixtureActive = $expect === 'fixture' && $area !== 'crontab';
$handlers = $objectManager->get(PostprocessingSubscribers::class)->merchantHandlers($objectManager->get(Hook::class));
$check(
    $handlers === ($fixtureActive ? [FIXTURE => FIXTURE_CLASS] : []),
    'merchant handlers detected: ' . json_encode($handlers)
);
if ($detectOnly) {
    $finish();
}

$fixtures = new ProbeFixtures($objectManager);
$postprocessor = $objectManager->get(OrderPostprocessor::class);
$composeOrder = $objectManager->get(ComposeOrder::class);
$subscriber = $fixtureActive ? FIXTURE_CLASS : null;
$format = static fn (float $amount): string => number_format($amount, 2, '.', '');

/**
 * A fresh request: payload as composed and context, for one request type.
 *
 * @return array{0: array, 1: array}
 */
$request = static function (string $type, float $shippingTax, bool $mistaxed = false, float $residual = 0.0) use (
    $objectManager,
    $fixtures,
    $composeOrder,
    $format
): array {
    $quote = $fixtures->quote([['probe-standard', 1]], false);
    $order = $objectManager->get(ComposeIntent::class)->toOrder($quote);
    $order->setData('two_shipping_tax_rate_source', 'none');
    $order->setData('two_shipping_tax_rate', 21.0);
    $order->setShippingTaxAmount($shippingTax);
    $lines = $composeOrder->composeLineItems($order);
    if ($mistaxed) {
        $lines[0]['tax_amount'] = $format((float)$lines[0]['tax_amount'] + 5.00);
        $lines[0]['gross_amount'] = $format((float)$lines[0]['gross_amount'] + 5.00);
    }
    $gross = array_sum(array_map('floatval', array_column($lines, 'gross_amount'))) + $residual;
    $tax = array_sum(array_map('floatval', array_column($lines, 'tax_amount')));
    $block = ['gross_amount' => $format($gross), 'net_amount' => $format($gross - $tax), 'tax_amount' => $format($tax), 'line_items' => $lines];

    switch ($type) {
        case Hook::REQUEST_ORDER_INTENT:
            return [$block + ['currency' => 'EUR'], ['quote' => $quote, 'intent_order' => $order]];
        case Hook::REQUEST_CAPTURE:
            $invoice = $objectManager->create(Invoice::class);
            $invoice->setOrder($order);
            $invoice->setShippingAmount($order->getShippingAmount());
            $invoice->setShippingTaxAmount($shippingTax);
            $invoice->setGrandTotal((float)$order->getShippingAmount() + $shippingTax);
            $invoice->setTaxAmount($shippingTax);
            return [['partial' => $block], ['order' => $order, 'invoice' => $invoice]];
        default:
            return [$block + ['currency' => 'EUR'], ['order' => $order]];
    }
};

/**
 * Send one request, armed as $mode. Returns the payload sent, or the refusal.
 *
 * @return array|LocalizedException
 */
$send = static function (string $type, array $payload, array $context, ?string $mode) use ($postprocessor, $subscriber) {
    if ($subscriber !== null) {
        $subscriber::$mode = $mode;
    }
    try {
        return $postprocessor->process($type, $payload, ['trigger' => 'ci_probe', 'endpoint' => 'probe'] + $context);
    } catch (LocalizedException $e) {
        return $e;
    } finally {
        if ($subscriber !== null) {
            $subscriber::$mode = null;
        }
    }
};
$refusedWith = static fn ($result, string $message, bool $shopMatch): bool => $result instanceof LocalizedException
    && str_starts_with($result->getMessage(), $message)
    && ($result instanceof ShopMatchRefusedException) === $shopMatch;
$debugLog = BP . '/var/log/two/debug.log';
$logSize = static function () use ($debugLog): int {
    clearstatcache();
    return is_file($debugLog) ? (int)filesize($debugLog) : 0;
};
$loggedSince = static fn (int $offset): string => is_file($debugLog) ? (string)file_get_contents($debugLog, false, null, $offset) : '';

$types = [Hook::REQUEST_ORDER_INTENT, Hook::REQUEST_ORDER_CREATE, Hook::REQUEST_ORDER_UPDATE, Hook::REQUEST_CAPTURE];
foreach ($types as $type) {
    $refusal = $type === Hook::REQUEST_CAPTURE ? MERCHANT_REFUSAL : BUYER_REFUSAL;

    // A passing order is sent as composed, with or without a subscriber.
    [$payload, $context] = $request($type, 6.09);
    $check($send($type, $payload, $context, null) === $payload, "$type: a reconciling fallback line is sent as composed");

    // The shop-match refusal: the default handler's, or delegated to the subscriber and logged.
    [$payload, $context] = $request($type, 0.00);
    $offset = $logSize();
    $result = $send($type, $payload, $context, null);
    if ($subscriber === null) {
        $check($refusedWith($result, $refusal, true), "$type: the default handler refuses the fallback mismatch as the builder did");
    } else {
        $check($result === $payload, "$type: with a subscriber the fallback mismatch is sent as returned");
        $logged = $loggedSince($offset);
        $check(
            str_contains($logged, 'OrderPostprocessingShopMatchDelegated') && str_contains($logged, FIXTURE),
            "$type: the delegation is logged naming the subscriber"
        );
        $check(
            $refusedWith($send($type, $payload, $context, 'opt_in'), $refusal, true),
            "$type: a subscriber calling the opt-in checks gets the refusal back"
        );
    }

    if ($type === Hook::REQUEST_CAPTURE) {
        // The capture builder itself, on a shipping-only invoice: it composes the line, the handler judges it.
        [, $context] = $request($type, 0.00);
        try {
            $payload = ['partial' => $objectManager->get(ComposeCapture::class)->execute($context['invoice'])];
            $result = $send($type, $payload, $context, null);
            $check(
                $subscriber === null ? $refusedWith($result, $refusal, true) : $result === $payload,
                "$type: the capture builder leaves the fallback mismatch to the hook, which "
                    . ($subscriber === null ? 'refuses it' : 'sends it')
            );
        } catch (\Throwable $e) {
            $check(false, "$type: the capture builder composed the invoice (" . get_class($e) . ': ' . $e->getMessage() . ')');
        }
    }

    // No line tax reconcile on any request: Two's API validates it (TWO-26284).
    [$payload, $context] = $request($type, 6.09, true);
    $result = $send($type, $payload, $context, null);
    $check($result === $payload, "$type: a composed line whose tax does not follow its rate is sent as composed");

    if ($subscriber === null) {
        continue;
    }
    [$payload, $context] = $request($type, 6.09);
    $result = $send($type, $payload, $context, 'lines_do_not_add_up');
    $check(is_array($result) && $result !== $payload, "$type: a subscriber's lines that do not add up are sent as returned");

    // A cost the shop adds to the total outside any carrier, itemised at 21% by the subscriber.
    [$payload, $context] = $request($type, 6.09, false, 12.10);
    $result = $send($type, $payload, $context, 'add_line');
    $block = is_array($result) ? ($result['partial'] ?? $result) : [];
    $added = array_values(array_filter($block['line_items'] ?? [], static fn (array $line): bool => $line['order_item_id'] === 'handling'));
    $check(
        count($added) === 1 && $added[0]['net_amount'] === '10.00' && $added[0]['tax_amount'] === '2.10'
            && $block['gross_amount'] === ($payload['partial'] ?? $payload)['gross_amount'],
        "$type: a line added for a cost outside the carrier, split at 21%, is sent as returned"
    );

    [$payload, $context] = $request($type, 6.09);
    $result = $send($type, $payload, $context, 'gross_change');
    $block = is_array($result) ? ($result['partial'] ?? $result) : [];
    $check(
        ($block['gross_amount'] ?? null) === $format((float)($payload['partial'] ?? $payload)['gross_amount'] + 1.00),
        "$type: a changed gross is sent as returned"
    );
}

$finish();
