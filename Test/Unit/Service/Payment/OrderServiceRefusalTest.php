<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Service\Payment;

use Magento\Framework\App\RequestInterface;
use Magento\Framework\DB\Transaction;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Url\DecoderInterface;
use Magento\Sales\Api\OrderPaymentRepositoryInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order\Payment\Transaction\BuilderInterface as TransactionBuilder;
use Magento\Sales\Model\Order\Payment\Transaction\Repository as PaymentTransactionRepository;
use Magento\Sales\Model\OrderFactory;
use Magento\Sales\Model\ResourceModel\Order as OrderResource;
use Magento\Sales\Model\Service\InvoiceService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\BrandOverlayRegistryInterface;
use Two\Gateway\Api\BrandRegistryInterface;
use Two\Gateway\Api\Config\RepositoryInterface as ConfigRepository;
use Two\Gateway\Api\Log\RepositoryInterface as LogRepository;
use Two\Gateway\Model\Two;
use Two\Gateway\Service\Api\Adapter;
use Two\Gateway\Service\Order\OrderPostprocessor;
use Two\Gateway\Service\Payment\OrderService;
use Two\Gateway\Service\Payment\RestoreQuote;
use Two\Gateway\Service\UrlCookie;

/**
 * TWO-26150: what the order fetch, confirm and cancel calls do with a refusal.
 * A bare non-2xx (a gateway's HTML page, no error_code) is a refusal. On the
 * confirm, a transport failure or a 5xx does not say whether the confirm
 * landed, so it is sent once more; confirming a confirmed order succeeds. A
 * second unanswered attempt is refused and logged for reconciliation, and
 * nothing is ever cancelled from here, since a cancel can race a confirm still
 * in flight.
 */
class OrderServiceRefusalTest extends TestCase
{
    private const FETCH = 'GET /v1/order/remote-order-id';
    private const CONFIRM = 'POST /v1/order/remote-order-id/confirm';
    private const CANCEL = 'POST /v1/order/remote-order-id/cancel';
    private const GATEWAY_502 = ['http_status' => 502];

    /** @var string[] the calls made, as "METHOD endpoint" */
    private $calls = [];

    /** @var array<int, array{0: string, 1: mixed}> error logs written */
    private $errorLogs = [];

    /**
     * @return array<int, array{string, array, ?string, array, bool, string}>
     *         [method, responses per call in order, refusal naming (null: none), calls made,
     *          unknown outcome logged, description]
     */
    public static function cases(): array
    {
        // Confirm responses are what executeWithStatus() returns for each case.
        $ok = ['status' => 200, 'body' => ['id' => 'remote-order-id', 'state' => 'CONFIRMED']];
        $timeout = ['status' => 0, 'body' => ['error_code' => 400, 'error_message' => 'Operation timed out']];
        $empty504 = ['status' => 504, 'body' => ['error_code' => 400, 'http_status' => 504, 'error_message' => 'Invalid API response from Two.']];
        $html502 = ['status' => 502, 'body' => self::GATEWAY_502];
        $invalid = ['status' => 400, 'body' => [
            'http_status' => 400, 'error_code' => 'ORDER_INVALID', 'error_message' => 'Order is invalid',
        ]];
        return [
            ['confirmOrder', [self::CONFIRM => [$timeout, $ok]], null,
                [self::CONFIRM, self::CONFIRM], false, 'timeout, then the retry confirms'],
            ['confirmOrder', [self::CONFIRM => [$empty504, $ok]], null,
                [self::CONFIRM, self::CONFIRM], false, 'empty-bodied 504, then the retry confirms'],
            ['confirmOrder', [self::CONFIRM => [$html502, $invalid]], 'Order is invalid',
                [self::CONFIRM, self::CONFIRM], false, '502, then the retry is refused by the API: refuse, no cancel'],
            ['confirmOrder', [self::CONFIRM => [$html502, $html502]], 'HTTP status 502',
                [self::CONFIRM, self::CONFIRM], true, '502 twice: refuse, log for reconciliation, no cancel'],
            ['confirmOrder', [self::CONFIRM => [$invalid]], 'Order is invalid',
                [self::CONFIRM], false, '4xx refused by the API: refuse, no retry'],
            ['cancelTwoOrder', [self::CANCEL => [self::GATEWAY_502]], 'HTTP status 502',
                [self::CANCEL], false, 'cancel 502 with an HTML body refuses'],
            ['getTwoOrderFromApi', [self::FETCH => [self::GATEWAY_502]], 'HTTP status 502',
                [self::FETCH], false, 'order fetch 502 with an HTML body refuses'],
        ];
    }

    #[DataProvider('cases')]
    public function testRefusal(
        string $method,
        array $responses,
        ?string $refusal,
        array $calls,
        bool $unknownLogged,
        string $description
    ): void {
        $order = new RefusalOrderStub($this->two());
        $order->setData('store_id', 1);
        $order->setData('two_order_id', 'remote-order-id');

        $thrown = null;
        try {
            $this->service($responses)->{$method}($order);
        } catch (LocalizedException $e) {
            $thrown = $e->getMessage();
        }

        if ($refusal === null) {
            $this->assertNull($thrown, $description);
        } else {
            $this->assertNotNull($thrown, "$description: refused");
            $this->assertStringContainsString($refusal, (string)$thrown, $description);
        }
        $this->assertSame($calls, $this->calls, "$description: calls made");
        $unknown = array_values(array_filter(
            $this->errorLogs,
            static fn (array $log): bool => $log[0] === 'confirm-outcome-unknown'
        ));
        $this->assertSame($unknownLogged ? 1 : 0, count($unknown), "$description: unknown outcome logged");
        if ($unknownLogged) {
            $this->assertSame('remote-order-id', $unknown[0][1]['two_order_id'], "$description: names the Two order");
        }
    }

    private function service(array $responses): OrderService
    {
        $next = function (string $method, string $endpoint) use (&$responses): array {
            $this->calls[] = "$method $endpoint";
            return array_shift($responses["$method $endpoint"]) ?? [];
        };
        $adapter = $this->createMock(Adapter::class);
        $adapter->method('execute')->willReturnCallback(
            fn (string $endpoint, array $payload = [], string $method = 'POST'): array => $next($method, $endpoint)
        );
        $adapter->method('executeWithStatus')->willReturnCallback(
            fn (string $endpoint, array $payload = [], string $method = 'POST'): array => $next($method, $endpoint)
        );
        $postprocessor = $this->createMock(OrderPostprocessor::class);
        $postprocessor->method('process')->willReturnArgument(1);
        $log = $this->createMock(LogRepository::class);
        $log->method('addErrorLog')->willReturnCallback(function (string $type, $data): void {
            $this->errorLogs[] = [$type, $data];
        });

        return new OrderService(
            $adapter,
            $this->createMock(RestoreQuote::class),
            $this->createMock(OrderResource::class),
            $this->createMock(OrderFactory::class),
            $this->createMock(UrlCookie::class),
            $this->createMock(InvoiceService::class),
            $this->createMock(Transaction::class),
            $this->createMock(DecoderInterface::class),
            $this->createMock(ConfigRepository::class),
            $this->createMock(BrandRegistryInterface::class),
            $this->createMock(RequestInterface::class),
            $this->createMock(TransactionBuilder::class),
            $this->createMock(PaymentTransactionRepository::class),
            $this->createMock(OrderPaymentRepositoryInterface::class),
            $this->createMock(OrderRepositoryInterface::class),
            $log,
            $this->createMock(BrandOverlayRegistryInterface::class),
            $postprocessor
        );
    }

    private function two(): Two
    {
        $two = $this->getMockBuilder(Two::class)->disableOriginalConstructor()->onlyMethods([])->getMock();
        $brand = $this->createMock(BrandRegistryInterface::class);
        $brand->method('getProductName')->willReturn('Two');
        (new \ReflectionProperty(Two::class, 'brandRegistry'))->setValue($two, $brand);
        (new \ReflectionProperty(Two::class, 'configRepository'))->setValue($two, $this->createMock(ConfigRepository::class));

        return $two;
    }
}

class RefusalOrderStub extends \Magento\Sales\Model\Order
{
    /** @var RefusalPaymentStub */
    private $payment;

    public function __construct(Two $methodInstance)
    {
        $this->payment = new RefusalPaymentStub($methodInstance);
    }

    public function getPayment(): RefusalPaymentStub
    {
        return $this->payment;
    }
}

class RefusalPaymentStub
{
    /** @var Two */
    private $methodInstance;

    public function __construct(Two $methodInstance)
    {
        $this->methodInstance = $methodInstance;
    }

    public function getMethodInstance(): Two
    {
        return $this->methodInstance;
    }
}
