<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * CI probe (ABN-621), run inside a real Magento: the payment term the buyer
 * selected is the term on the order create request. Each case records the
 * selection the way the storefront does, through the real term-selection
 * endpoint class (the checkout session's priced term) and the payment's
 * additional data (the order's own term), places the order through the
 * payment method's authorize() and the real Adapter and Magento's Curl
 * client, and reads terms.duration_days off the POST /v1/order body that
 * reached a local HTTP server (wire-echo-terms-server.php). The merchant
 * offers 30, 60 and 90 days with 30 as its default, so a selection that
 * is dropped anywhere on the chain shows up as the default on the wire, or
 * as a refusal. Usage, from the Magento root:
 *   php <plugin>/Test/Integration/order-term-wire-probe.php
 */
declare(strict_types=1);

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\State;
use Magento\Framework\Encryption\EncryptorInterface;
use Two\Gateway\Api\Webapi\TermSelectionInterface;
use Two\Gateway\Model\Two;
use Two\Gateway\Service\Order\ComposeIntent;
use Two\Gateway\Service\UrlCookie;
use Two\Gateway\Test\Integration\ProbeFixtures;

require getcwd() . '/app/bootstrap.php';
require __DIR__ . '/probe-fixtures.php';

$failures = [];
$check = static function (bool $ok, string $what) use (&$failures): void {
    echo ($ok ? 'ok   ' : 'FAIL ') . $what . PHP_EOL;
    if (!$ok) {
        $failures[] = $what;
    }
};

// The plugin reads its API base URL from TWO_API_BASE_URL in developer mode.
$port = 18766;
$log = sys_get_temp_dir() . '/two-term-wire-probe.jsonl';
@unlink($log);
$server = proc_open(
    [PHP_BINARY, '-S', "127.0.0.1:$port", __DIR__ . '/wire-echo-terms-server.php'],
    [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
    $pipes,
    null,
    ['WIRE_ECHO_LOG' => $log]
);
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) {
    usleep(100000);
}
if ($i === 50) {
    echo "FAIL the local API server did not start on port $port" . PHP_EOL;
    exit(1);
}
putenv("TWO_API_BASE_URL=http://127.0.0.1:$port");
register_shutdown_function(static function () use ($server): void {
    proc_terminate($server);
});
$received = static function () use ($log): array {
    $lines = is_file($log) ? file($log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
    @unlink($log);
    return array_map(static fn (string $l): array => json_decode($l, true), $lines);
};

$objectManager = Bootstrap::create(BP, $_SERVER)->getObjectManager();
$objectManager->get(State::class)->setAreaCode('frontend');
$env = $objectManager->get(\Magento\Framework\App\DeploymentConfig::class)->get('MAGE_MODE');
if ($env !== 'developer') {
    // Without the override the calls below would go to the real API.
    echo "FAIL the shop runs in $env mode, so the API base URL override does not apply" . PHP_EOL;
    exit(1);
}

$fixtures = new ProbeFixtures($objectManager);
$fixtures->configure(ProbeFixtures::EXCLUSIVE);
// The merchant record is cached per key and environment, so this probe's key
// keeps its three-term record apart from the other probes'.
$configureTerms = static function (string $termsType) use ($fixtures, $objectManager): void {
    $fixtures->configure([
        'payment/two_payment/active' => 1,
        'payment/two_payment/mode' => 'sandbox',
        'payment/two_payment/api_key' => $objectManager->get(EncryptorInterface::class)->encrypt('probe-terms-key'),
        'payment/two_payment/payment_terms' => '30,60,90',
        'payment/two_payment/default_payment_term' => 30,
        'payment/two_payment/payment_terms_type' => $termsType,
        // Ten chip selections in a run, from one address, must not meet the endpoint's ceiling.
        'payment/two_payment/disable_rate_limit' => 1,
    ]);
};

// [term the buyer chose at the chips (null: none recorded in the session),
//  term the payment carries (null: none), terms type, term on the wire (null: refused, nothing sent),
//  end-of-month marker expected, description]
$cases = [
    [30, 30, 'standard', 30, false, 'the default term, as the control'],
    [60, 60, 'standard', 60, false, 'a non-default term (60)'],
    [90, 90, 'standard', 90, false, 'a non-default term (90)'],
    [60, 60, 'end_of_month', 60, true, 'a non-default term (60) on end-of-month terms'],
    [90, 90, 'end_of_month', 90, true, 'a non-default term (90) on end-of-month terms'],
    [null, 90, 'standard', 90, false, 'a term the payment carries with no session term recorded'],
    [90, null, 'standard', null, false, 'a term priced in the session but missing from the payment is refused, not sent as the default'],
    [90, 60, 'standard', null, false, 'a payment term that differs from the priced one is refused'],
    [45, 45, 'standard', null, false, 'a term the merchant does not offer is refused'],
    [null, 45, 'standard', null, false, 'a term the merchant does not offer, with no session term, is refused'],
];

$session = $objectManager->get(CheckoutSession::class);
$termSelection = $objectManager->get(TermSelectionInterface::class);
$cookie = new class extends UrlCookie {
    public function __construct()
    {
    }

    public function set(string $value, int $duration = 86400): void
    {
    }

    public function delete(): void
    {
    }
};

foreach ($cases as [$chipTerm, $paymentTerm, $termsType, $wireTerm, $endOfMonth, $description]) {
    $configureTerms($termsType);
    $quote = $fixtures->quote([['probe-standard', 2]], false);
    $session->clearStorage();
    $session->replaceQuote($quote);

    // The buyer's chip click: the session term the surcharge is priced on.
    $selectionRefused = null;
    if ($chipTerm !== null) {
        try {
            $termSelection->selectTerm((string)$quote->getId(), $chipTerm);
        } catch (\Throwable $e) {
            $selectionRefused = get_class($e);
        }
        $offered = $chipTerm !== 45;
        $check(
            $offered ? $selectionRefused === null : $selectionRefused !== null,
            "$description: the chip selection was " . ($offered ? 'recorded' : 'refused') . ' (' . ($selectionRefused ?? 'no error') . ')'
        );
        $check(
            (int)$session->getTwoSelectedTerm() === ($offered ? $chipTerm : 0),
            "$description: the session holds the selection (" . var_export($session->getTwoSelectedTerm(), true) . ')'
        );
    }

    // Placement: the quote's payment carries the data the storefront posts, and
    // core's own conversion copies it onto the order's payment.
    $placing = $session->getQuote();
    $additionalData = [
        'companyName' => 'Probe Buyer',
        'companyId' => '123456789',
        'telephone' => '+31201234567',
    ];
    if ($paymentTerm !== null) {
        $additionalData['selectedTerm'] = $paymentTerm;
    }
    $placing->getPayment()->importData(['method' => 'two_payment', 'additional_data' => $additionalData]);
    $created = $objectManager->get(ComposeIntent::class)->toOrder($placing);
    $payment = $objectManager->get(\Magento\Quote\Model\Quote\Payment\ToOrderPayment::class)
        ->convert($placing->getPayment());
    $created->setPayment($payment);
    $method = $objectManager->create(Two::class, ['urlCookie' => $cookie]);
    $payment->setMethodInstance($method);
    $method->setInfoInstance($payment);
    $check(
        $payment->getAdditionalInformation('selectedTerm') === $paymentTerm,
        "$description: the payment recorded the term (" . var_export($payment->getAdditionalInformation('selectedTerm'), true) . ')'
    );

    $received();
    $refusal = null;
    try {
        $method->authorize($payment, $created->getGrandTotal());
    } catch (\Throwable $e) {
        $refusal = get_class($e) . ': ' . $e->getMessage();
    }
    $posted = array_values(array_filter(
        $received(),
        static fn (array $c): bool => $c['method'] === 'POST' && $c['path'] === '/v1/order'
    ));

    if ($wireTerm === null) {
        $check($refusal !== null, "$description: placement refused" . ($refusal === null ? ' (it was authorized)' : " ($refusal)"));
        $check(count($posted) === 0, "$description: no create request reached the API (" . count($posted) . ')');
        continue;
    }
    $check($refusal === null, "$description: authorized" . ($refusal === null ? '' : " ($refusal)"));
    $check(count($posted) === 1, "$description: one create request reached the API (" . count($posted) . ')');
    $terms = (json_decode($posted[0]['body'] ?? '', true) ?: [])['terms'] ?? [];
    $check(
        ($terms['duration_days'] ?? null) === $wireTerm,
        "$description: terms.duration_days on the wire is $wireTerm (" . var_export($terms['duration_days'] ?? null, true) . ')'
    );
    $check(($terms['type'] ?? null) === 'NET_TERMS', "$description: terms.type on the wire is NET_TERMS");
    $check(
        ($terms['duration_days_calculated_from'] ?? null) === ($endOfMonth ? 'END_OF_MONTH' : null),
        "$description: terms.duration_days_calculated_from is " . ($endOfMonth ? 'END_OF_MONTH' : 'absent')
        . ' (' . var_export($terms['duration_days_calculated_from'] ?? null, true) . ')'
    );
}
$configureTerms('standard');
$fixtures->configure(['payment/two_payment/disable_rate_limit' => 0] + ProbeFixtures::EXCLUSIVE);

if ($failures) {
    echo count($failures) . ' order term wire probe check(s) failed' . PHP_EOL;
    exit(1);
}
echo 'order term wire probe passed' . PHP_EOL;
