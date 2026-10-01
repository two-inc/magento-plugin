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
 * confirm it is also ambiguous, because the confirm may have landed before the
 * gateway failed, so the order is re-read: a confirmed order carries on, and
 * anything else is cancelled at Two before the caller fails the Magento order.
 */
class OrderServiceRefusalTest extends TestCase
{
    private const FETCH = 'GET /v1/order/remote-order-id';
    private const CONFIRM = 'POST /v1/order/remote-order-id/confirm';
    private const CANCEL = 'POST /v1/order/remote-order-id/cancel';
    private const GATEWAY_502 = ['http_status' => 502];

    /** @var string[] the calls made, as "METHOD endpoint" */
    private $calls = [];

    /**
     * @return array<int, array{string, array, ?string, array, string}>
     *         [method, responses by call, refusal naming (null: none), calls made, description]
     */
    public static function cases(): array
    {
        $invalid = ['http_status' => 400, 'error_code' => 'ORDER_INVALID', 'error_message' => 'Order is invalid'];
        return [
            ['confirmOrder', [self::CONFIRM => self::GATEWAY_502, self::FETCH => ['id' => 'x', 'state' => 'CONFIRMED']],
                null, [self::CONFIRM, self::FETCH], 'confirm 502, the order is confirmed: carry on'],
            ['confirmOrder', [self::CONFIRM => self::GATEWAY_502, self::FETCH => ['id' => 'x', 'state' => 'VERIFIED']],
                'HTTP status 502', [self::CONFIRM, self::FETCH, self::CANCEL], 'confirm 502, not confirmed: cancel, then refuse'],
            ['confirmOrder', [self::CONFIRM => self::GATEWAY_502, self::FETCH => self::GATEWAY_502, self::CANCEL => self::GATEWAY_502],
                'HTTP status 502', [self::CONFIRM, self::FETCH, self::CANCEL], 'confirm 502, re-read and cancel fail too: still refuse'],
            ['confirmOrder', [self::CONFIRM => $invalid],
                'Order is invalid', [self::CONFIRM], 'confirm 4xx with error_code: refuse, no re-read, no cancel'],
        ];
    }

    #[DataProvider('cases')]
    public function testRefusal(string $method, array $responses, ?string $refusal, array $calls, string $description): void
    {
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
    }

    private function service(array $responses): OrderService
    {
        $adapter = $this->createMock(Adapter::class);
        $adapter->method('execute')->willReturnCallback(
            function (string $endpoint, array $payload = [], string $method = 'POST') use ($responses): array {
                $this->calls[] = "$method $endpoint";
                return $responses["$method $endpoint"] ?? [];
            }
        );
        $postprocessor = $this->createMock(OrderPostprocessor::class);
        $postprocessor->method('process')->willReturnArgument(1);

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
            $this->createMock(LogRepository::class),
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
