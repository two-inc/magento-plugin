<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Service\Order;

use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\OrderStatusHistoryRepositoryInterface;
use Magento\Sales\Model\Order;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\Config\RepositoryInterface as ConfigRepository;
use Two\Gateway\Api\Log\RepositoryInterface as LogRepository;
use Two\Gateway\Api\OrderPostprocessingInterface as Hook;
use Two\Gateway\Service\Order\OrderPostprocessor;
use Two\Gateway\Service\Order\PostprocessingTotals;
use Two\Gateway\Test\Unit\Service\Order\Doubles\PostprocessorFactory;
use Two\OrderPostprocessingFixture\Plugin\Subscriber;

require_once __DIR__ . '/../../../Integration/OrderPostprocessingFixture/Plugin/Subscriber.php';

/**
 * The postprocessing hook (TWO-26092), driven through the CI fixture
 * subscriber: what it returns is sent, after the internal-consistency checks
 * (TWO-26276), and otherwise only a subscriber bug refuses.
 */
class OrderPostprocessorTest extends TestCase
{
    use PostprocessorFactory;

    /** @var array<int, array{0: string, 1: mixed}> */
    private $errorLog = [];

    /** @var array<int, array{0: string, 1: mixed}> */
    private $debugLog = [];

    /** @var string[] */
    private $comments = [];

    protected function setUp(): void
    {
        Subscriber::$mode = null;
        Subscriber::$calls = [];
    }

    /**
     * @dataProvider unchangedCases
     */
    public function testNoSubscriberSendsTheComposedPayloadByteForByte(
        string $requestType,
        string $payload,
        string $description
    ): void {
        $composed = self::payload($payload);

        $sent = $this->postprocessor(false, 2, false)->process($requestType, $composed, $this->context());

        $this->assertSame(json_encode($composed), json_encode($sent), $description);
        $this->assertSame([], $this->debugLog, $description);
        $this->assertSame([], $this->comments, $description);
    }

    public static function unchangedCases(): array
    {
        return [
            [Hook::REQUEST_ORDER_INTENT, 'intent', 'order intent'],
            [Hook::REQUEST_ORDER_CREATE, 'order', 'order create'],
            [Hook::REQUEST_ORDER_UPDATE, 'order', 'order update'],
            [Hook::REQUEST_ORDER_CONFIRM, 'none', 'confirm, no body'],
            [Hook::REQUEST_CAPTURE, 'partial capture', 'partial capture'],
            [Hook::REQUEST_CAPTURE, 'none', 'whole-order capture, no body'],
            [Hook::REQUEST_REFUND, 'refund', 'refund'],
            [Hook::REQUEST_CANCEL, 'none', 'cancel, no body'],
            [Hook::REQUEST_ORDER_UPDATE, 'unbalanced order', 'the plugin\'s own payload with a residual, sent as today'],
        ];
    }

    /**
     * Given an armed subscriber; When a request is postprocessed; Then the
     * payload at each JSON pointer is what the subscriber declared.
     *
     * @dataProvider editCases
     */
    public function testASubscriberEditIsSentAsReturned(
        string $requestType,
        string $payload,
        string $mode,
        string $expected,
        string $description
    ): void {
        Subscriber::$mode = $mode;

        $sent = $this->postprocessor()->process($requestType, self::payload($payload), $this->context());

        foreach (self::expectations()[$expected] as $pointer => $value) {
            $this->assertSame($value, self::at($sent, $pointer), $description . ' ' . $pointer);
        }
        $this->assertCount(1, $this->comments, $description);
        $this->assertStringContainsString('/line_items/1/', $this->comments[0], $description);
        $changed = $this->debugEntries('OrderPostprocessingChanged');
        $this->assertCount(1, $changed, $description);
        $this->assertStringContainsString(
            '/line_items/1/net_amount',
            implode(' ', array_keys($changed[0]['diff'])),
            $description . ': the debug log keys each change by its JSON pointer'
        );
    }

    public static function editCases(): array
    {
        return [
            [Hook::REQUEST_ORDER_CREATE, 'order', Subscriber::MODE_RESPLIT, 'order re-split', '29.00 shipping re-split to 23.97 + 5.03 on create'],
            [Hook::REQUEST_ORDER_INTENT, 'intent', Subscriber::MODE_RESPLIT, 'intent re-split', 'the same re-split on intent, which carries no subtotals'],
            [Hook::REQUEST_CAPTURE, 'partial capture', Subscriber::MODE_RESPLIT, 'capture re-split', 'the same re-split inside a partial capture'],
            [Hook::REQUEST_REFUND, 'refund', Subscriber::MODE_RESPLIT, 'refund re-split', 'a full shipping refund mirrors the re-split'],
            [Hook::REQUEST_ORDER_CREATE, 'order', Subscriber::MODE_GROSS_CHANGE, 'gross change', 'a gross change is the merchant\'s to make: no platform comparison refuses it'],
            [Hook::REQUEST_ORDER_CREATE, 'order', Subscriber::MODE_RESPLIT_WITHOUT_TOTALS, 'totals broken', 're-split lines under stale totals are sent: the API validates them'],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function expectations(): array
    {
        $line = [
            '/line_items/1/net_amount' => '23.97',
            '/line_items/1/tax_amount' => '5.03',
            '/line_items/1/gross_amount' => '29.00',
            '/line_items/1/tax_rate' => '0.210000',
        ];
        $totals = ['/net_amount' => '123.97', '/tax_amount' => '26.03', '/gross_amount' => '150.00'];
        $subtotals = [
            '/tax_subtotals' => [['taxable_amount' => '123.97', 'tax_amount' => '26.03', 'tax_rate' => '0.210000']],
        ];
        $partial = [];
        foreach ($line + $totals + $subtotals as $pointer => $value) {
            $partial['/partial' . $pointer] = $value;
        }

        return [
            'order re-split' => $line + $totals + $subtotals,
            'intent re-split' => $line + $totals,
            'capture re-split' => $partial,
            'refund re-split' => $line + $subtotals + ['/amount' => '150.00'],
            'totals broken' => $line + ['/net_amount' => '129.00', '/tax_amount' => '21.00', '/tax_subtotals' => self::subtotals()],
            'gross change' => [
                '/line_items/1/gross_amount' => '30.00',
                '/gross_amount' => '151.00',
                '/net_amount' => '130.00',
            ],
        ];
    }

    /**
     * @dataProvider refusalCases
     */
    public function testASubscriberBugRefusesWithItsNamedCode(
        string $requestType,
        string $payload,
        ?string $mode,
        string $code,
        string $description
    ): void {
        Subscriber::$mode = $mode;

        try {
            $this->postprocessor()->process($requestType, self::payload($payload), $this->context());
            $this->fail($description . ': nothing was refused');
        } catch (LocalizedException $e) {
            $refusal = array_values(array_filter(
                $this->errorLog,
                static fn (array $entry): bool => $entry[0] === 'OrderPostprocessingRefused'
            ));
            $this->assertSame($code, $refusal[0][1]['code'] ?? null, $description);
            $buyerFacing = in_array($requestType, [Hook::REQUEST_ORDER_INTENT, Hook::REQUEST_ORDER_CREATE], true);
            $this->assertSame(
                !$buyerFacing,
                str_contains($e->getMessage(), $code),
                $description . ': the code reaches the merchant, never the buyer'
            );
        }
    }

    public static function refusalCases(): array
    {
        return [
            [Hook::REQUEST_ORDER_UPDATE, 'order', Subscriber::MODE_THROW, 'TWO_ORDER_POSTPROCESSING_HOOK_FAILED', 'a subscriber that throws'],
            [Hook::REQUEST_ORDER_CREATE, 'order', Subscriber::MODE_RETURN_NON_ARRAY, 'TWO_ORDER_POSTPROCESSING_HOOK_FAILED', 'a subscriber that returns no array'],
            [Hook::REQUEST_CAPTURE, 'partial capture', Subscriber::MODE_NOT_ENCODABLE, 'TWO_ORDER_POSTPROCESSING_HOOK_FAILED', 'a subscriber whose result cannot be JSON-encoded'],
        ];
    }

    /**
     * The line tax reconcile is an internal-consistency check (TWO-26276): it
     * runs after the hook, on what is about to be sent, with or without a
     * subscriber, on the requests it always ran on. The refusal is as before.
     *
     * @dataProvider mistaxedCases
     */
    public function testTheLineTaxReconcileRefusesWhatIsAboutToBeSent(
        string $requestType,
        bool $subscriber,
        ?string $mode,
        bool $composedMistaxed,
        string $description
    ): void {
        Subscriber::$mode = $mode;
        $payload = self::orderPayload();
        if ($composedMistaxed) {
            $payload['line_items'][0]['tax_amount'] = '25.00';
            $payload['line_items'][0]['gross_amount'] = '125.00';
        }

        try {
            $this->postprocessor(false, 2, $subscriber)->process($requestType, $payload, $this->context());
            $this->fail($description . ': nothing was refused');
        } catch (LocalizedException $e) {
            $this->assertSame('This order could not be placed. Please contact the merchant.', $e->getMessage(), $description);
            $this->assertSame(['TaxReconciliationFailed'], array_column($this->errorLog, 0), $description);
            $this->assertCount($subscriber ? 1 : 0, Subscriber::$calls, $description . ': the hook ran first');
        }
    }

    public static function mistaxedCases(): array
    {
        return [
            [Hook::REQUEST_ORDER_CREATE, false, null, true, 'create, no subscriber, a mistaxed composed line'],
            [Hook::REQUEST_ORDER_UPDATE, false, null, true, 'update, no subscriber, a mistaxed composed line'],
            [Hook::REQUEST_ORDER_CREATE, true, null, true, 'create, a subscriber that passes a mistaxed composed line on'],
            [Hook::REQUEST_ORDER_CREATE, true, Subscriber::MODE_RESPLIT, true, 'create, a subscriber that re-splits shipping and leaves the mistaxed line'],
            [Hook::REQUEST_ORDER_CREATE, true, Subscriber::MODE_LINES_DO_NOT_ADD_UP, false, 'create, a subscriber whose lines do not add up'],
            [Hook::REQUEST_ORDER_UPDATE, true, Subscriber::MODE_LINES_DO_NOT_ADD_UP, false, 'update, a subscriber whose lines do not add up'],
        ];
    }

    /**
     * Intent, capture and refund never ran the line tax reconcile, and still do not.
     *
     * @dataProvider uncheckedCases
     */
    public function testRequestsTheReconcileNeverCoveredStillSendWhatTheyAreGiven(
        string $requestType,
        string $payload,
        string $description
    ): void {
        Subscriber::$mode = Subscriber::MODE_LINES_DO_NOT_ADD_UP;

        $sent = $this->postprocessor()->process($requestType, self::payload($payload), $this->context());

        $this->assertNotSame(self::payload($payload), $sent, $description . ': the subscriber edit is sent');
        $this->assertSame([], $this->errorLog, $description);
    }

    public static function uncheckedCases(): array
    {
        return [
            [Hook::REQUEST_ORDER_INTENT, 'intent', 'order intent'],
            [Hook::REQUEST_CAPTURE, 'partial capture', 'partial capture'],
            [Hook::REQUEST_REFUND, 'refund', 'refund'],
        ];
    }

    /**
     * TWO-26117: a shipping line with no rate recorded and the control blank
     * goes at 0% with its tax as charged. The builder reconcile leaves it to
     * the API, so it reaches the hook.
     *
     * @dataProvider builderGatedRequests
     */
    public function testANoRateShippingLineWithTaxReachesTheHook(string $requestType): void
    {
        Subscriber::$mode = null;
        $payload = self::orderPayload();
        $payload['line_items'][1]['tax_amount'] = '5.03';
        $payload['line_items'][1]['gross_amount'] = '34.03';

        $sent = $this->postprocessor()->process($requestType, $payload, $this->context());

        $this->assertSame($payload, $sent, $requestType);
        $this->assertSame([], $this->errorLog, $requestType);
        $this->assertCount(1, Subscriber::$calls, $requestType . ': the hook ran');
    }

    public static function builderGatedRequests(): array
    {
        return [[Hook::REQUEST_ORDER_CREATE], [Hook::REQUEST_ORDER_UPDATE]];
    }

    /**
     * A body-less request is never blocked: Magento has already cancelled or
     * confirmed, and a Two order left live could still be invoiced.
     *
     * @dataProvider bodylessCases
     */
    public function testABodylessRequestIsSentEmptyWhateverTheSubscriberDoes(
        string $requestType,
        string $mode,
        string $code,
        string $description
    ): void {
        Subscriber::$mode = $mode;

        $sent = $this->postprocessor()->process($requestType, [], $this->context());

        $this->assertSame([], $sent, $description);
        $this->assertSame('OrderPostprocessingIgnored', $this->errorLog[0][0] ?? null, $description);
        $this->assertSame($code, $this->errorLog[0][1]['code'] ?? null, $description);
    }

    public static function bodylessCases(): array
    {
        return [
            [Hook::REQUEST_CANCEL, Subscriber::MODE_THROW, 'TWO_ORDER_POSTPROCESSING_HOOK_FAILED', 'a throwing subscriber on cancel'],
            [Hook::REQUEST_ORDER_CONFIRM, Subscriber::MODE_THROW, 'TWO_ORDER_POSTPROCESSING_HOOK_FAILED', 'a throwing subscriber on confirm'],
            [Hook::REQUEST_CANCEL, Subscriber::MODE_BODY_ON_BODYLESS, 'TWO_ORDER_POSTPROCESSING_BODY_NOT_ACCEPTED', 'a body added to cancel'],
            [Hook::REQUEST_ORDER_CONFIRM, Subscriber::MODE_BODY_ON_BODYLESS, 'TWO_ORDER_POSTPROCESSING_BODY_NOT_ACCEPTED', 'a body added to confirm'],
        ];
    }

    /**
     * @dataProvider contextCases
     */
    public function testTheContextCarriesTheContractKeys(bool $fallback, ?int $taxClass, array $expected): void
    {
        $this->postprocessor($fallback, $taxClass)->process(
            Hook::REQUEST_REFUND,
            self::refundPayload(),
            $this->context() + ['creditmemo' => 'memo']
        );

        $context = Subscriber::$calls[0]['context'];
        foreach ($expected as $key => $value) {
            $this->assertSame($value, $context[$key], $key);
        }
        $this->assertSame('memo', $context['creditmemo']);
        $this->assertCount(1, Subscriber::$calls);
    }

    public static function contextCases(): array
    {
        $base = [
            'request_type' => 'refund',
            'trigger' => 'test',
            'endpoint' => '/v1/order/{id}/refund',
            'contract_version' => 1,
        ];

        return [
            'fallback off' => [false, 2, $base + ['shipping_tax_rate' => 0.21, 'fallback_shipping_tax_rate' => null]],
            'fallback on' => [true, 2, $base + ['shipping_tax_rate' => 0.21, 'fallback_shipping_tax_rate' => 0.21]],
            'no shipping class' => [true, null, $base + ['shipping_tax_rate' => null, 'fallback_shipping_tax_rate' => null]],
        ];
    }

    private function context(): array
    {
        $order = new Order();
        $order->setData('store_id', 1);
        $order->setData('entity_id', 7);
        // Placed with the shipping tax fallback blank: no shop-match check applies.
        $order->setData('two_shipping_tax_rate_source', 'none');

        // The order stands in as the converted quote an intent carries too.
        return ['trigger' => 'test', 'endpoint' => '/v1/order/{id}/refund', 'order' => $order, 'intent_order' => $order];
    }

    /**
     * @param bool $fallback
     * @param int|null $taxClass
     * @param bool $subscriber false for no subscriber at all: only the plugin's default handler
     */
    private function postprocessor(bool $fallback = false, ?int $taxClass = 2, bool $subscriber = true): OrderPostprocessor
    {
        $config = $this->createMock(ConfigRepository::class);
        $config->method('getShippingTaxClassId')->willReturn($taxClass);
        $config->method('isShippingTaxFallbackEnabled')->willReturn($fallback);

        $log = $this->createMock(LogRepository::class);
        $log->method('addErrorLog')->willReturnCallback(function (string $type, $data) {
            $this->errorLog[] = [$type, $data];
        });
        $log->method('addDebugLog')->willReturnCallback(function (string $type, $data) {
            $this->debugLog[] = [$type, $data];
        });

        $hook = $this->hookChain($log, $subscriber ? [
            'two_order_postprocessing_fixture' => new Subscriber(new PostprocessingTotals(), $this->shopMatchChecks($log)),
        ] : []);

        $comments = &$this->comments;
        $historyRepository = new class ($comments) implements OrderStatusHistoryRepositoryInterface {
            /** @var string[] */
            private $comments;

            public function __construct(array &$comments)
            {
                $this->comments = &$comments;
            }

            public function save($history)
            {
                $this->comments[] = $history->getComment();
                return $history;
            }
        };

        return $this->buildPostprocessor($hook, $config, $log, $historyRepository, 21.0);
    }

    /**
     * @param string $type
     * @return array<int, mixed> the data of each debug entry of that type
     */
    private function debugEntries(string $type): array
    {
        return array_values(array_map(
            static fn (array $entry) => $entry[1],
            array_filter($this->debugLog, static fn (array $entry): bool => $entry[0] === $type)
        ));
    }

    /**
     * @param array $payload
     * @param string $pointer
     * @return mixed
     */
    private static function at(array $payload, string $pointer)
    {
        foreach (array_slice(explode('/', $pointer), 1) as $key) {
            $payload = $payload[$key] ?? null;
        }

        return $payload;
    }

    private static function payload(string $name): array
    {
        $unbalanced = self::orderPayload();
        $unbalanced['gross_amount'] = '151.00';

        return [
            'none' => [],
            'intent' => self::intentPayload(),
            'order' => self::orderPayload(),
            'unbalanced order' => $unbalanced,
            'partial capture' => ['partial' => self::capturePayload()],
            'refund' => self::refundPayload(),
        ][$name];
    }

    /** One product at 21% and 29.00 of shipping the shop recorded as untaxed. */
    private static function lines(): array
    {
        return [
            [
                'order_item_id' => 11,
                'type' => 'PHYSICAL',
                'gross_amount' => '121.00',
                'net_amount' => '100.00',
                'tax_amount' => '21.00',
                'discount_amount' => '0.00',
                'tax_rate' => '0.210000',
                'tax_class_name' => 'VAT 21.00%',
                'unit_price' => '100.000000',
                'quantity' => 1,
            ],
            [
                'order_item_id' => 'shipping',
                'type' => 'SHIPPING_FEE',
                'gross_amount' => '29.00',
                'net_amount' => '29.00',
                'tax_amount' => '0.00',
                'discount_amount' => '0.00',
                'tax_rate' => '0.000000',
                'tax_class_name' => 'VAT 0.00%',
                'unit_price' => '29.000000',
                'quantity' => 1,
            ],
        ];
    }

    private static function subtotals(): array
    {
        return [
            ['taxable_amount' => '100.00', 'tax_amount' => '21.00', 'tax_rate' => '0.210000'],
            ['taxable_amount' => '29.00', 'tax_amount' => '0.00', 'tax_rate' => '0.000000'],
        ];
    }

    private static function orderPayload(): array
    {
        return [
            'currency' => 'EUR',
            'discount_amount' => '0.00',
            'gross_amount' => '150.00',
            'net_amount' => '129.00',
            'tax_amount' => '21.00',
            'tax_subtotals' => self::subtotals(),
            'line_items' => self::lines(),
            'merchant_order_id' => '000000042',
        ];
    }

    private static function intentPayload(): array
    {
        return [
            'gross_amount' => '150.00',
            'net_amount' => '129.00',
            'tax_amount' => '21.00',
            'currency' => 'EUR',
            'line_items' => self::lines(),
            'buyer' => ['company' => ['organization_number' => '123']],
        ];
    }

    private static function capturePayload(): array
    {
        return [
            'discount_amount' => '0.00',
            'gross_amount' => '150.00',
            'line_items' => self::lines(),
            'net_amount' => '129.00',
            'tax_amount' => '21.00',
            'tax_subtotals' => self::subtotals(),
        ];
    }

    private static function refundPayload(): array
    {
        return [
            'amount' => '150.00',
            'currency' => 'EUR',
            'line_items' => self::lines(),
            'tax_subtotals' => self::subtotals(),
        ];
    }
}
