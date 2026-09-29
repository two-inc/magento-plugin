<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Service\Order;

use Magento\Customer\Api\GroupRepositoryInterface;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\OrderStatusHistoryRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Status\HistoryFactory;
use Magento\Tax\Model\Calculation as TaxCalculation;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\Config\RepositoryInterface as ConfigRepository;
use Two\Gateway\Api\Log\RepositoryInterface as LogRepository;
use Two\Gateway\Api\OrderPostprocessingInterface as Hook;
use Two\Gateway\Model\OrderPostprocessing;
use Two\Gateway\Service\Order as OrderService;
use Two\Gateway\Service\Order\OrderPostprocessor;
use Two\Gateway\Service\Order\PostprocessingTotals;
use Two\OrderPostprocessingFixture\Plugin\Subscriber;

require_once __DIR__ . '/../../../Integration/OrderPostprocessingFixture/Plugin/Subscriber.php';

/**
 * The postprocessing hook and the gates it runs on the payload it returns
 * (TWO-26092), driven through the CI fixture subscriber.
 */
class OrderPostprocessorTest extends TestCase
{
    /** @var array<int, array{0: string, 1: mixed}> */
    private $errorLog = [];

    /** @var array<int, array{0: string, 1: mixed}> */
    private $infoLog = [];

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

        $sent = $this->postprocessor()->process($requestType, $composed, $this->context());

        $this->assertSame(json_encode($composed), json_encode($sent), $description);
        $this->assertSame([], $this->infoLog, $description);
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
        ];
    }

    /**
     * Given an armed subscriber; When a request is postprocessed; Then the
     * payload at each JSON pointer is what the subscriber declared.
     *
     * @dataProvider editCases
     */
    public function testASubscriberEditThatKeepsTheGatesIsSent(
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
        $this->assertSame('OrderPostprocessingChanged', $this->infoLog[0][0], $description);
    }

    public static function editCases(): array
    {
        return [
            [Hook::REQUEST_ORDER_CREATE, 'order', Subscriber::MODE_RESPLIT, 'order re-split', '29.00 shipping re-split to 23.97 + 5.03 on create'],
            [Hook::REQUEST_ORDER_INTENT, 'intent', Subscriber::MODE_RESPLIT, 'intent re-split', 'the same re-split on intent, which carries no subtotals'],
            [Hook::REQUEST_CAPTURE, 'partial capture', Subscriber::MODE_RESPLIT, 'capture re-split', 'the same re-split inside a partial capture'],
            [Hook::REQUEST_REFUND, 'refund', Subscriber::MODE_RESPLIT, 'refund re-split', 'a full shipping refund mirrors the re-split'],
            [Hook::REQUEST_ORDER_CREATE, 'order', Subscriber::MODE_GROSS_CHANGE, 'gross change', 'a gross change is the merchant\'s to make: no platform comparison refuses it'],
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
    public function testAGateFailureRefusesWithItsNamedCode(
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
            [Hook::REQUEST_ORDER_CREATE, 'order', Subscriber::MODE_RESPLIT_WITHOUT_TOTALS, 'TWO_ORDER_POSTPROCESSING_TOTALS_INCONSISTENT', 're-split lines, stale order totals'],
            [Hook::REQUEST_CAPTURE, 'partial capture', Subscriber::MODE_LINE_OFF, 'TWO_ORDER_POSTPROCESSING_LINE_INCONSISTENT', 'a line whose net, tax and gross no longer agree'],
            [Hook::REQUEST_REFUND, 'refund', Subscriber::MODE_SUBTOTALS_STALE, 'TWO_ORDER_POSTPROCESSING_SUBTOTALS_INCONSISTENT', 're-split lines and totals, stale subtotals'],
            [Hook::REQUEST_CANCEL, 'none', Subscriber::MODE_BODY_ON_BODYLESS, 'TWO_ORDER_POSTPROCESSING_BODY_NOT_ACCEPTED', 'a body on a request the API defines none for'],
            [Hook::REQUEST_ORDER_CONFIRM, 'none', Subscriber::MODE_BODY_ON_BODYLESS, 'TWO_ORDER_POSTPROCESSING_BODY_NOT_ACCEPTED', 'the same on confirm'],
            [Hook::REQUEST_ORDER_UPDATE, 'order', Subscriber::MODE_THROW, 'TWO_ORDER_POSTPROCESSING_HOOK_FAILED', 'a subscriber that throws'],
            [Hook::REQUEST_ORDER_CREATE, 'order', Subscriber::MODE_RETURN_NON_ARRAY, 'TWO_ORDER_POSTPROCESSING_HOOK_FAILED', 'a subscriber that returns no array'],
            [Hook::REQUEST_ORDER_UPDATE, 'unbalanced order', null, 'TWO_ORDER_TOTALS_INCONSISTENT', 'the plugin\'s own inconsistent payload, no subscriber involved'],
        ];
    }

    /**
     * With no subscriber the line tax gate fails exactly as it did inside
     * ComposeOrder before it moved behind the hook.
     */
    public function testAnUnchangedCreateFailsTheLineTaxGateWithTodaysError(): void
    {
        $payload = self::orderPayload();
        $payload['line_items'][0]['tax_amount'] = '25.00';
        $payload['line_items'][0]['gross_amount'] = '125.00';

        try {
            $this->postprocessor()->process(Hook::REQUEST_ORDER_CREATE, $payload, $this->context());
            $this->fail('nothing was refused');
        } catch (LocalizedException $e) {
            $this->assertSame('This order could not be placed. Please contact the merchant.', $e->getMessage());
            $this->assertSame(['TaxReconciliationFailed'], array_column($this->errorLog, 0));
        }
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

        return ['trigger' => 'test', 'endpoint' => '/v1/order/{id}/refund', 'order' => $order];
    }

    private function postprocessor(bool $fallback = false, ?int $taxClass = 2): OrderPostprocessor
    {
        $postprocessor = $this->getMockBuilder(OrderPostprocessor::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getMock();

        $config = $this->createMock(ConfigRepository::class);
        $config->method('getShippingTaxClassId')->willReturn($taxClass);
        $config->method('isShippingTaxFallbackEnabled')->willReturn($fallback);
        $postprocessor->configRepository = $config;

        $log = $this->createMock(LogRepository::class);
        $log->method('addErrorLog')->willReturnCallback(function (string $type, $data) {
            $this->errorLog[] = [$type, $data];
        });
        $log->method('addLog')->willReturnCallback(function (string $type, $data) {
            $this->infoLog[] = [$type, $data];
        });

        $taxCalculation = $this->createMock(TaxCalculation::class);
        $taxCalculation->method('getRateRequest')->willReturn(new DataObject());
        $taxCalculation->method('getRate')->willReturn(21.0);

        $plugin = new Subscriber(new PostprocessingTotals());
        $hook = new class ($plugin) implements Hook {
            /** @var Subscriber */
            private $plugin;

            public function __construct(Subscriber $plugin)
            {
                $this->plugin = $plugin;
            }

            // Stands in for the generated interceptor: the default, then the plugin, under the interface's type.
            public function process(array $payload, array $context): array
            {
                $result = (new OrderPostprocessing())->process($payload, $context);

                return $this->plugin->afterProcess($this, $result, $payload, $context);
            }
        };

        $comments = &$this->comments;
        $historyFactory = new HistoryFactory();
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

        foreach ([
            [OrderService::class, 'logRepository', $log],
            [OrderService::class, 'taxCalculation', $taxCalculation],
            [OrderService::class, 'groupRepository', $this->createMock(GroupRepositoryInterface::class)],
            [OrderPostprocessor::class, 'hook', $hook],
            [OrderPostprocessor::class, 'historyFactory', $historyFactory],
            [OrderPostprocessor::class, 'historyRepository', $historyRepository],
        ] as [$class, $property, $value]) {
            (new \ReflectionProperty($class, $property))->setValue($postprocessor, $value);
        }

        return $postprocessor;
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
