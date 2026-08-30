<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Service\Payment;

use Magento\Framework\App\RequestInterface;
use Magento\Framework\DB\Transaction;
use Magento\Framework\Url\DecoderInterface;
use Magento\Sales\Api\OrderPaymentRepositoryInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order\Payment\Transaction\BuilderInterface as TransactionBuilder;
use Magento\Sales\Model\Order\Payment\Transaction\Repository as PaymentTransactionRepository;
use Magento\Sales\Model\OrderFactory;
use Magento\Sales\Model\ResourceModel\Order as OrderResource;
use Magento\Sales\Model\Service\InvoiceService;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\BrandOverlayRegistryInterface;
use Two\Gateway\Api\BrandRegistryInterface;
use Two\Gateway\Api\Config\RepositoryInterface as ConfigRepository;
use Two\Gateway\Api\Log\RepositoryInterface as LogRepository;
use Two\Gateway\Service\Api\Adapter;
use Two\Gateway\Service\Payment\OrderService;
use Two\Gateway\Service\Payment\RestoreQuote;
use Two\Gateway\Service\UrlCookie;

/**
 * These calls are admin- and cron-initiated, so the request carries no store
 * scope. A null store id resolves the DEFAULT scope's API key and firewall
 * token, which on a multi-store install is a different merchant's.
 */
class OrderServiceStoreScopeTest extends TestCase
{
    private const ORDER_STORE_ID = 7;

    /** @var array|null captured [endpoint, payload, method, storeId] */
    private $capturedApiCall;

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function orderApiCalls(): array
    {
        return [
            'fetch'   => ['getTwoOrderFromApi', '/v1/order/remote-order-id'],
            'confirm' => ['confirmOrder', '/v1/order/remote-order-id/confirm'],
            'cancel'  => ['cancelTwoOrder', '/v1/order/remote-order-id/cancel'],
        ];
    }

    /**
     * @dataProvider orderApiCalls
     */
    public function testTheCallIsScopedToTheOrdersStore(string $method, string $endpoint): void
    {
        $order = new StoreScopeOrderStub();
        $order->setData('store_id', self::ORDER_STORE_ID);
        $order->setData('two_order_id', 'remote-order-id');

        $this->makeService()->{$method}($order);

        $this->assertNotNull($this->capturedApiCall, 'the API call is made');
        $this->assertSame($endpoint, $this->capturedApiCall[0]);
        $this->assertSame(self::ORDER_STORE_ID, $this->capturedApiCall[3]);
    }

    private function makeService(): OrderService
    {
        $apiAdapter = $this->createMock(Adapter::class);
        $apiAdapter->method('execute')->willReturnCallback(function (
            string $endpoint,
            array $payload = [],
            string $method = 'POST',
            ?int $storeId = null
        ): array {
            $this->capturedApiCall = [$endpoint, $payload, $method, $storeId];
            return ['id' => 'remote-order-id'];
        });

        return new OrderService(
            $apiAdapter,
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
            $this->createMock(BrandOverlayRegistryInterface::class)
        );
    }
}

class StoreScopeOrderStub extends \Magento\Sales\Model\Order
{
    public function getPayment(): StoreScopePaymentStub
    {
        return new StoreScopePaymentStub();
    }
}

class StoreScopePaymentStub
{
    public function getMethodInstance(): StoreScopeMethodInstanceStub
    {
        return new StoreScopeMethodInstanceStub();
    }
}

class StoreScopeMethodInstanceStub
{
    /**
     * @param array $response
     * @return string|null
     */
    public function getErrorFromResponse($response)
    {
        return null;
    }
}
