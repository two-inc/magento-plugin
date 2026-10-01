<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * CI probe (TWO-26150), run inside a real Magento: drives the real admin
 * order-edit observer and the self-invoice upload's first request through
 * the real Adapter and Magento's real Curl client to a local HTTP server
 * (wire-echo-server.php), and checks what arrived on the wire. That server
 * refuses anything but PUT on both routes, as the API does. Usage, from the
 * Magento root:
 *   php <plugin>/Test/Integration/api-wire-probe.php
 */
declare(strict_types=1);

use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\State;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Two\Gateway\Observer\SalesOrderAddressUpdate;
use Two\Gateway\Service\Invoice\UploadService;
use Two\Gateway\Service\Order\ComposeIntent;
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
$port = 18765;
$log = sys_get_temp_dir() . '/two-wire-probe.jsonl';
@unlink($log);
$server = proc_open(
    [PHP_BINARY, '-S', "127.0.0.1:$port", __DIR__ . '/wire-echo-server.php'],
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
$objectManager->get(State::class)->setAreaCode('adminhtml');
$env = $objectManager->get(\Magento\Framework\App\DeploymentConfig::class)->get('MAGE_MODE');
if ($env !== 'developer') {
    // Without the override the calls below would go to the real API.
    echo "FAIL the shop runs in $env mode, so the API base URL override does not apply" . PHP_EOL;
    exit(1);
}

// ── 1. Admin order edit ─────────────────────────────────────────────
$fixtures = new ProbeFixtures($objectManager);
$fixtures->configure(ProbeFixtures::EXCLUSIVE);
$order = $objectManager->get(ComposeIntent::class)->toOrder($fixtures->quote([['probe-standard', 1]], false));
$order->setTwoOrderId('probe-order-id');
$order->setTwoOrderReference('probe-reference');
$order->setPayment($objectManager->create(\Magento\Sales\Model\Order\Payment::class));
$order->getPayment()->setMethod('two_payment')->setAdditionalInformation([
    'buyer' => [
        'company' => ['company_name' => 'Probe Buyer', 'organization_number' => '123456789'],
        'representative' => ['phone_number' => '+31201234567'],
    ],
    'terms' => ['type' => 'NET_TERMS', 'duration_days' => 30],
]);
$repository = new class ($order) implements OrderRepositoryInterface {
    /** @var Order */
    private $order;

    public function __construct(Order $order)
    {
        $this->order = $order;
    }

    public function get($id)
    {
        return $this->order;
    }

    public function getList(\Magento\Framework\Api\SearchCriteriaInterface $searchCriteria)
    {
        throw new \LogicException('not used by the probe');
    }

    public function delete(\Magento\Sales\Api\Data\OrderInterface $entity)
    {
        throw new \LogicException('not used by the probe');
    }

    public function save(\Magento\Sales\Api\Data\OrderInterface $entity)
    {
        return $entity;
    }
};
$observer = $objectManager->create(SalesOrderAddressUpdate::class, ['orderRepository' => $repository]);
// [Two order id, edit sent, history comment contains, description]
$edits = [
    ['probe-order-id', true, 'accepted', 'an accepted edit'],
    ['refused-order-id', true, 'failed', 'a refused edit'],
    ['fulfilled-order-id', false, 'already invoiced', 'an edit to a fully fulfilled order'],
];
foreach ($edits as [$twoOrderId, $editSent, $recorded, $description]) {
    $order->setTwoOrderId($twoOrderId);
    $observer->execute(new Observer(['event' => new Event(['order_id' => 1])]));

    // Every edit first looks up the order's state; the edit itself is the PUT after it.
    $calls = $received();
    $lookup = $calls[0] ?? [];
    $check(
        ($lookup['method'] ?? null) === 'GET' && ($lookup['path'] ?? null) === "/v1/order/$twoOrderId",
        "$description first looked up the order (" . ($lookup['method'] ?? 'none') . ')'
    );
    $puts = array_values(array_filter($calls, static fn (array $c): bool => $c['method'] !== 'GET'));
    $check(
        count($puts) === ($editSent ? 1 : 0),
        "$description made " . ($editSent ? 'one edit request' : 'no edit request') . ' (' . count($puts) . ')'
    );
    if ($editSent) {
        $edit = $puts[0] ?? [];
        $sent = json_decode($edit['body'] ?? '', true);
        $check(($edit['method'] ?? null) === 'PUT', "$description arrived as PUT (" . ($edit['method'] ?? 'none') . ')');
        $check(($edit['path'] ?? null) === "/v1/order/$twoOrderId", "$description arrived at /v1/order/{id}");
        $check(($edit['content_type'] ?? null) === 'application/json', "$description carried a JSON body");
        $check(
            is_array($sent) && !empty($sent['line_items']) && isset($sent['gross_amount']),
            "$description carried the composed order"
        );
    }
    // The history collection reads newest first, so take the newest by id.
    $comments = [];
    foreach ($order->getAllStatusHistory() as $entry) {
        $comments[(int)$entry->getId()] = (string)$entry->getComment();
    }
    krsort($comments);
    $last = (string)reset($comments);
    $check(str_contains($last, $recorded), "$description is recorded as $recorded in the order history ($last)");
}

// ── 2. Self-invoice upload, first request ───────────────────────────
$upload = $objectManager->get(UploadService::class);
$request = new \ReflectionMethod($upload, 'requestSignedUploadUrl');
$result = $request->invoke($upload, 'probe-invoice-id', 0);

$calls = $received();
$step1 = $calls[0] ?? [];
$check(count($calls) === 1, 'the upload request made one request (' . count($calls) . ')');
$check(($step1['method'] ?? null) === 'PUT', 'the upload request arrived as PUT (' . ($step1['method'] ?? 'none') . ')');
$check(
    ($step1['path'] ?? null) === '/uploads/v1/invoice/probe-invoice-id/external_invoice/0',
    'the upload request arrived at its route (' . ($step1['path'] ?? 'none') . ')'
);
$check(
    json_decode($step1['body'] ?? '', true) === ['content_type' => 'application/pdf'],
    'the upload request carried its payload'
);
$check(
    ($result['success'] ?? false) === true && ($result['reference'] ?? null) === 'probe-reference',
    'the upload request returned the signed target (' . json_encode($result) . ')'
);

if ($failures) {
    echo count($failures) . ' API wire probe check(s) failed' . PHP_EOL;
    exit(1);
}
