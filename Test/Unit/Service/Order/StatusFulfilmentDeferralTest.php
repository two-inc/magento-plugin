<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Service\Order;

use Magento\Framework\App\State;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Message\ManagerInterface as MessageManager;
use Magento\Sales\Api\CreditmemoManagementInterface;
use Magento\Sales\Api\Data\CreditmemoInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Api\OrderStatusHistoryRepositoryInterface;
use Magento\Sales\Api\RefundInvoiceInterface;
use Magento\Sales\Api\RefundOrderInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\Order\Payment;
use Magento\Sales\Model\Order\Status\HistoryFactory;
use Magento\Sales\Model\OrderFactory;
use Magento\Sales\Model\Service\InvoiceService;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\BrandOverlayRegistryInterface;
use Two\Gateway\Api\BrandRegistryInterface;
use Two\Gateway\Api\Config\RepositoryInterface as ConfigRepository;
use Two\Gateway\Api\Log\RepositoryInterface as LogRepository;
use Two\Gateway\Observer\SalesOrderSaveAfter;
use Two\Gateway\Plugin\Model\Sales\FulfilAfterRefund\CreditmemoManagement;
use Two\Gateway\Plugin\Model\Sales\FulfilAfterRefund\RefundInvoice;
use Two\Gateway\Plugin\Model\Sales\FulfilAfterRefund\RefundOrder;
use Two\Gateway\Service\Api\Adapter;
use Two\Gateway\Service\Order\ComposeShipment;
use Two\Gateway\Service\Order\FulfilmentDeferral;
use Two\Gateway\Service\Order\OrderPostprocessor;
use Two\Gateway\Service\Order\StatusFulfilment;

/**
 * TWO-26302: a fulfil-on status reached during a refund is fulfilled once the
 * refund call returns, from the order and credit memos as saved; outside a
 * refund, after the outermost commit. A failure is commented, not thrown.
 *
 * A small shop stands in for core: saving the order persists it and fires
 * sales_order_save_after inside the save's own transaction, the composer reads
 * the credit memos saved so far, and a fresh load reads what was persisted.
 */
class StatusFulfilmentDeferralTest extends TestCase
{
    use BuildsStatusFulfilment;

    private const ORDER_ID = 7;

    /** @var array persisted order: status, items as [ordered, shipped, refunded], payment info */
    private $saved = [];

    /** @var float[] refunded quantity per saved credit memo */
    private $savedMemos = [];

    /** @var array[] fulfilment bodies sent to Two */
    private $sent = [];

    /** @var Order[] the order instance each fulfilment was built from */
    private $composedFrom = [];

    /** @var int */
    private $orderSaves = 0;

    /** @var string[] order comments written */
    private $comments = [];

    /** @var array[] error log entries */
    private $errors = [];

    /** @var string[] admin messages */
    private $adminMessages = [];

    /** @var \Throwable|null what the next call to Two throws */
    private $twoFails;

    /** @var FakeOrderResource */
    private $resource;

    /** @var FulfilmentDeferral */
    private $deferral;

    /** @var SalesOrderSaveAfter */
    private $observer;

    /** @var StatusFulfilment */
    private $service;

    protected function setUp(): void
    {
        $this->resource = new FakeOrderResource();
        $this->newRequest();
    }

    /**
     * @dataProvider refundRoutes
     */
    public function testARefundFulfilsOnceFromSavedState(string $route, string $description): void
    {
        $order = $this->order(['status' => 'processing', 'items' => [[2, 1, 0]]]);
        $this->persist($order);

        $result = $this->refund($route, $order, 1.0);

        $this->assertSame('refund-result', $result, $description . ': the refund call returns what core returned');
        $this->assertCount(1, $this->sent, $description . ': one fulfilment');
        $this->assertSame(1.0, $this->sent[0]['partial']['net'], $description . ': the saved memo is netted out');
        $this->assertNotSame($order, $this->composedFrom[0], $description . ': composed from a fresh load');
        $this->assertTrue(
            !empty($this->saved['info']['marked_completed']),
            $description . ': the marker is saved'
        );
        $this->assertSame([], $this->resource->callbacks, $description . ': nothing left waiting on a commit');
        $this->assertSame(0, $this->resource->level, $description . ': transactions balanced');
    }

    public static function refundRoutes(): array
    {
        return [
            ['rest_order', 'REST order refund (order then memo, inside the order lock)'],
            ['rest_invoice', 'REST invoice refund (order then memo, inside the order lock)'],
            ['admin', 'admin credit memo (memo then order, inside one transaction)'],
            ['rest_order_unlocked', 'order refund saving order then memo with no transaction'],
            ['rest_invoice_unlocked', 'invoice refund saving order then memo with no transaction'],
        ];
    }

    public function testTheObserverQueuesInsideARefundAndSendsNothing(): void
    {
        $order = $this->order(['status' => 'complete', 'items' => [[1, 1, 0]]]);
        $this->deferral->enterRefund();

        $this->saveOrder($order);

        $this->assertSame([], $this->sent, 'nothing sent inside the refund call');
        $this->assertSame([], $this->resource->callbacks, 'no commit callback inside the refund call');
        $this->assertSame([self::ORDER_ID], $this->deferral->takeQueue(), 'the order is queued');
    }

    public function testTheWholeOrderCheckStillRefusesInsideARefund(): void
    {
        $order = $this->order(['status' => 'complete', 'items' => [[1, 1, 0], [1, 0, 0]]]);
        $this->deferral->enterRefund();

        try {
            $this->observer->execute($this->saveEvent($order));
            $this->fail('the unshipped item refuses the save');
        } catch (LocalizedException $e) {
            $this->assertSame([], $this->deferral->takeQueue(), 'a refused save queues nothing');
        }
    }

    public function testARefundThatThrowsFulfilsNothing(): void
    {
        $order = $this->order(['status' => 'processing', 'items' => [[2, 2, 0]]]);
        $plugin = new RefundOrder($this->service);
        try {
            $plugin->aroundExecute(
                $this->createMock(RefundOrderInterface::class),
                function () use ($order): void {
                    $order->setData('status', 'complete');
                    $this->saveOrder($order);
                    throw new \RuntimeException('memo save failed');
                },
                self::ORDER_ID
            );
            $this->fail('the refund failure reaches the caller');
        } catch (\RuntimeException $e) {
            $this->assertSame('memo save failed', $e->getMessage());
        }

        $plugin->aroundExecute($this->createMock(RefundOrderInterface::class), static fn () => 1, self::ORDER_ID);

        $this->assertSame([], $this->sent, 'the failed refund\'s queue is discarded, not flushed by the next refund');
    }

    /**
     * @dataProvider failures
     */
    public function testAFailedFulfilmentIsCommentedNotThrown(
        string $area,
        \Throwable $failure,
        int $expectedAdminMessages,
        string $expectedReason,
        string $description
    ): void {
        $this->twoFails = $failure;
        $this->newRequest($area);
        $order = $this->order(['status' => 'processing', 'items' => [[2, 1, 0]]]);
        $this->persist($order);

        $result = $this->refund('rest_order', $order, 1.0);

        $this->assertSame('refund-result', $result, $description . ': the refund still succeeds');
        $this->assertSame(
            ['Failed to fulfil order with Two. Reason: ' . $expectedReason],
            $this->comments,
            $description . ': one order comment saying why'
        );
        $this->assertCount($expectedAdminMessages, $this->adminMessages, $description . ': admin messages');
        $this->assertCount(1, $this->errors, $description . ': logged at error');
        $this->assertTrue(empty($this->saved['info']['marked_completed']), $description . ': no marker');

        // A later save in a fulfil-on status retries, in its own request.
        $this->twoFails = null;
        $this->newRequest($area);
        $this->saveOrder($this->freshOrder());
        $this->assertCount(1, $this->sent, $description . ': the next save retries and fulfils');
    }

    public static function failures(): array
    {
        $refused = new LocalizedException(new \Magento\Framework\Phrase('Order is not fulfillable'));

        return [
            ['adminhtml', $refused, 1, 'Order is not fulfillable', 'Two refuses, in the admin'],
            ['webapi_rest', $refused, 0, 'Order is not fulfillable', 'Two refuses, over REST'],
            ['adminhtml', new \RuntimeException('timeout'), 1, 'timeout', 'the call fails, in the admin'],
        ];
    }

    public function testAStaleCopySavedLaterInTheRequestDoesNotFulfilAgain(): void
    {
        $order = $this->order(['status' => 'processing', 'items' => [[2, 1, 0]]]);
        $this->persist($order);
        $this->refund('rest_order', $order, 1.0);

        // The in-memory order never saw the marker the fresh copy saved.
        $this->saveOrder($order);

        $this->assertCount(1, $this->sent, 'one fulfilment');
    }

    public function testTwoSavesInOneRefundFulfilOnce(): void
    {
        $order = $this->order(['status' => 'complete', 'items' => [[1, 1, 0]]]);
        (new RefundOrder($this->service))->aroundExecute(
            $this->createMock(RefundOrderInterface::class),
            function () use ($order): void {
                $this->saveOrder($order);
                $this->saveOrder($order);
            },
            self::ORDER_ID
        );

        $this->assertCount(1, $this->sent, 'one fulfilment');
        $this->assertSame(2 + 1, $this->orderSaves, 'the two refund saves and the fulfilment\'s own');
    }

    /**
     * @dataProvider staleQueues
     */
    public function testTheFlushRechecksTheSavedOrder(
        array $savedAfterQueueing,
        int $expectedSent,
        array $expectedComments,
        string $description
    ): void {
        $order = $this->order(['status' => 'complete', 'items' => [[2, 2, 0]]]);
        (new RefundOrder($this->service))->aroundExecute(
            $this->createMock(RefundOrderInterface::class),
            function () use ($order, $savedAfterQueueing): void {
                $this->saveOrder($order);
                $this->saved = $savedAfterQueueing + $this->saved;
            },
            self::ORDER_ID
        );

        $this->assertCount($expectedSent, $this->sent, $description . ': fulfilments');
        $this->assertSame($expectedComments, $this->comments, $description . ': comments');
    }

    public static function staleQueues(): array
    {
        return [
            [['status' => 'closed'], 0, [], 'no longer in a fulfil-on status'],
            [['info' => ['marked_completed' => true]], 0, [], 'fulfilled by another path meanwhile'],
            [
                ['items' => [[2, 1, 0]]],
                0,
                ['Failed to fulfil order with Two. Reason: Two requires whole order to be shipped before it can be fulfilled.'],
                'no longer wholly shipped',
            ],
        ];
    }

    public function testEverythingRefundedSendsAndSavesNothing(): void
    {
        $order = $this->order(['status' => 'processing', 'items' => [[1, 0, 0]]]);
        $this->persist($order);

        $this->refund('rest_order', $order, 1.0);

        $this->assertSame([], $this->sent, 'nothing left to fulfil');
        $this->assertSame(1, $this->orderSaves, 'only the refund\'s own order save');
    }

    public function testOnlyTheOutermostRefundCallFlushes(): void
    {
        $order = $this->order(['status' => 'complete', 'items' => [[1, 1, 0]]]);
        $sentWhenInnerReturned = null;
        (new CreditmemoManagement($this->service))->aroundRefund(
            $this->createMock(CreditmemoManagementInterface::class),
            function () use ($order, &$sentWhenInnerReturned) {
                (new RefundOrder($this->service))->aroundExecute(
                    $this->createMock(RefundOrderInterface::class),
                    fn () => $this->saveOrder($order),
                    self::ORDER_ID
                );
                $sentWhenInnerReturned = count($this->sent);
            },
            $this->createMock(CreditmemoInterface::class)
        );

        $this->assertSame(0, $sentWhenInnerReturned, 'nothing sent while the outer refund call runs');
        $this->assertCount(1, $this->sent, 'sent once the outer call returns');
    }

    public function testARefundInsideAnOuterTransactionWaitsForItsCommit(): void
    {
        $order = $this->order(['status' => 'processing', 'items' => [[2, 1, 0]]]);
        $this->persist($order);
        $this->resource->beginTransaction();

        $this->refund('rest_order', $order, 1.0);
        $sentBeforeCommit = count($this->sent);
        $this->resource->commit();

        $this->assertSame(0, $sentBeforeCommit, 'nothing sent before the outer commit');
        $this->assertCount(1, $this->sent, 'sent after the outer commit');
        $this->assertSame(1.0, $this->sent[0]['partial']['net'], 'from saved state');
    }

    /**
     * @dataProvider outsideARefund
     */
    public function testAStatusChangeOutsideARefund(
        bool $inTransaction,
        ?bool $commits,
        int $expectedSent,
        bool $expectedFresh,
        string $description
    ): void {
        $order = $this->order(['status' => 'complete', 'items' => [[1, 1, 0]]]);
        $this->persist($order);
        if ($inTransaction) {
            $this->resource->beginTransaction();
        }

        $this->observer->execute($this->saveEvent($order));
        $sentBeforeCommit = count($this->sent);
        if ($commits === true) {
            $this->resource->commit();
        } elseif ($commits === false) {
            $this->resource->rollBack();
        }

        if ($inTransaction) {
            $this->assertSame(0, $sentBeforeCommit, $description . ': nothing sent inside the transaction');
        }
        $this->assertCount($expectedSent, $this->sent, $description . ': fulfilments');
        if ($expectedSent) {
            $this->assertSame(
                $expectedFresh,
                $this->composedFrom[0] !== $order,
                $description . ': composed from a fresh load'
            );
        }
    }

    public static function outsideARefund(): array
    {
        return [
            [false, null, 1, false, 'no transaction open: fulfilled at once, as before'],
            [true, true, 1, true, 'inside a transaction that commits: fulfilled after the commit'],
            [true, false, 0, false, 'inside a transaction that rolls back: nothing sent'],
        ];
    }

    /**
     * @dataProvider plugins
     */
    public function testEachPluginPassesTheCallThrough(callable $call, array $expectedArguments, string $description): void
    {
        $received = null;
        $result = $call($this->service, function (...$arguments) use (&$received) {
            $received = $arguments;
            return 'core-result';
        });

        $this->assertSame($expectedArguments, $received, $description . ': arguments');
        $this->assertSame('core-result', $result, $description . ': result');
    }

    public static function plugins(): array
    {
        $memo = self::stubOf(CreditmemoInterface::class);

        return [
            [
                static fn ($service, $proceed) => (new CreditmemoManagement($service))->aroundRefund(
                    self::stubOf(CreditmemoManagementInterface::class),
                    $proceed,
                    $memo,
                    true
                ),
                [$memo, true],
                'credit memo management',
            ],
            [
                static fn ($service, $proceed) => (new RefundOrder($service))->aroundExecute(
                    self::stubOf(RefundOrderInterface::class),
                    $proceed,
                    5,
                    ['item'],
                    true,
                    false
                ),
                [5, ['item'], true, false, null, null],
                'REST order refund',
            ],
            [
                static fn ($service, $proceed) => (new RefundInvoice($service))->aroundExecute(
                    self::stubOf(RefundInvoiceInterface::class),
                    $proceed,
                    6,
                    ['item'],
                    true,
                    false,
                    true
                ),
                [6, ['item'], true, false, true, null, null],
                'REST invoice refund',
            ],
        ];
    }

    /**
     * @param class-string $interface
     * @return object
     */
    private static function stubOf(string $interface)
    {
        return eval('return new class implements \\' . $interface . ' {};');
    }

    /**
     * Runs a refund of the first item through the route's plugin, saving the
     * order and the memo in the order core saves them: the REST routes inside
     * the order lock's transaction unless the route is "_unlocked".
     *
     * @return mixed
     */
    private function refund(string $route, Order $order, float $qty)
    {
        $refundOnOrder = function () use ($order, $qty): void {
            $item = $order->getAllVisibleItems()[0];
            $item->setData('qtyRefunded', $item->getData('qtyRefunded') + $qty);
            $order->setData('status', 'complete');
        };
        $locked = strpos($route, '_unlocked') === false;
        $restRefund = function () use ($order, $qty, $refundOnOrder, $locked): string {
            if ($locked) {
                $this->resource->beginTransaction();
            }
            $refundOnOrder();
            $this->saveOrder($order);
            $this->saveMemo($qty);
            if ($locked) {
                $this->resource->commit();
            }
            return 'refund-result';
        };
        switch (str_replace('_unlocked', '', $route)) {
            case 'rest_order':
                return (new RefundOrder($this->service))->aroundExecute(
                    $this->createMock(RefundOrderInterface::class),
                    $restRefund,
                    self::ORDER_ID
                );
            case 'rest_invoice':
                return (new RefundInvoice($this->service))->aroundExecute(
                    $this->createMock(RefundInvoiceInterface::class),
                    $restRefund,
                    3
                );
            default:
                return (new CreditmemoManagement($this->service))->aroundRefund(
                    $this->createMock(CreditmemoManagementInterface::class),
                    function () use ($order, $qty, $refundOnOrder): string {
                        $this->resource->beginTransaction();
                        $this->saveMemo($qty);
                        $refundOnOrder();
                        $this->saveOrder($order);
                        $this->resource->commit();
                        return 'refund-result';
                    },
                    $this->createMock(CreditmemoInterface::class)
                );
        }
    }

    /**
     * A new request: fresh request-scoped state, the same database.
     */
    private function newRequest(string $area = 'adminhtml'): void
    {
        $config = $this->createMock(ConfigRepository::class);
        $config->method('getFulfillTrigger')->willReturn('complete');
        $config->method('getFulfillOrderStatusList')->willReturn(['complete']);
        $brand = $this->createMock(BrandRegistryInterface::class);
        $brand->method('getProductName')->willReturn('Two');
        $overlay = $this->createMock(BrandOverlayRegistryInterface::class);
        $overlay->method('isTwoStackMethod')->willReturn(true);
        $postprocessor = $this->createMock(OrderPostprocessor::class);
        $postprocessor->method('process')->willReturnCallback(function ($type, array $payload, array $context): array {
            $this->composedFrom[] = $context['order'];
            return $payload;
        });
        $state = new State();
        $state->setAreaCode($area);

        $this->deferral = new FulfilmentDeferral();
        $this->service = $this->buildStatusFulfilment([
            'configRepository' => $config,
            'brandRegistry' => $brand,
            'overlayRegistry' => $overlay,
            'apiAdapter' => $this->adapter(),
            'orderPostprocessor' => $postprocessor,
            'composeShipment' => $this->composeShipment(),
            'historyFactory' => $this->historyFactory(),
            'invoiceService' => $this->invoiceService(),
            'orderStatusHistoryRepository' => $this->historyRepository(),
            'deferral' => $this->deferral,
            'orderFactory' => $this->orderFactory(),
            'orderRepository' => $this->orderRepository(),
            'orderResource' => $this->resource,
            'messageManager' => $this->messageManager(),
            'appState' => $state,
            'logRepository' => $this->logRepository(),
        ]);
        $this->observer = new SalesOrderSaveAfter($this->service, $this->deferral);
    }

    /**
     * Core's order save: one transaction, the row written, then
     * sales_order_save_after, then the commit.
     */
    private function saveOrder(Order $order): void
    {
        $this->orderSaves++;
        $this->resource->beginTransaction();
        try {
            $this->persist($order);
            $this->observer->execute($this->saveEvent($order));
        } catch (\Throwable $e) {
            $this->resource->rollBack();
            throw $e;
        }
        $this->resource->commit();
    }

    private function saveMemo(float $qty): void
    {
        $this->resource->beginTransaction();
        $this->savedMemos[] = $qty;
        $this->resource->commit();
    }

    private function persist(Order $order): void
    {
        $items = [];
        foreach ($order->getAllVisibleItems() as $item) {
            $items[] = [$item->getData('qtyOrdered'), $item->getData('qtyShipped'), $item->getData('qtyRefunded')];
        }
        $this->saved = [
            'status' => $order->getStatus(),
            'items' => $items,
            'info' => (array)$order->getPayment()->getAdditionalInformation(),
        ];
    }

    private function freshOrder(): Order
    {
        return $this->order($this->saved);
    }

    private function order(array $state): Order
    {
        $order = new class extends Order {
            /** @var callable|null */
            public $loader;

            public function getAllVisibleItems(): array
            {
                return $this->getData('visible_items');
            }

            public function load($id): self
            {
                ($this->loader)($this, $id);
                return $this;
            }
        };
        $visible = [];
        foreach ($state['items'] as $i => [$ordered, $shipped, $refunded]) {
            // The DataObject stub keys its magic getters in camelCase.
            $visible[] = new DataObject([
                'sku' => chr(65 + $i),
                'qtyOrdered' => (float)$ordered,
                'qtyShipped' => (float)$shipped,
                'qtyRefunded' => (float)$refunded,
                'qtyCanceled' => 0.0,
            ]);
        }
        $order->setData('visible_items', $visible);
        $order->setData('entity_id', self::ORDER_ID);
        $order->setData('store_id', 1);
        $order->setData('two_order_id', 'remote-order-id');
        $order->setData('status', $state['status']);
        $order->setData('shipping_refunded', 0.0);
        $payment = new Payment();
        $payment->setData('method', 'two_payment');
        $payment->setData('method_instance', new DeferralMethodInstance());
        $payment->setData('additional_information', $state['info'] ?? []);
        $order->setData('payment', $payment);

        return $order;
    }

    private function saveEvent(Order $order): DeferralSaveEvent
    {
        return new DeferralSaveEvent(new DataObject(['order' => $order]));
    }

    private function orderFactory(): OrderFactory
    {
        $factory = $this->getMockBuilder(OrderFactory::class)->addMethods(['create'])->getMock();
        $factory->method('create')->willReturnCallback(function (): Order {
            $order = $this->order(['status' => null, 'items' => []]);
            $order->loader = function (Order $order, $id): void {
                $loaded = $this->freshOrder();
                foreach (['visible_items', 'status', 'payment'] as $key) {
                    $order->setData($key, $loaded->getData($key));
                }
                $order->setData('entity_id', (int)$id === self::ORDER_ID ? self::ORDER_ID : null);
            };
            return $order;
        });

        return $factory;
    }

    private function orderRepository(): OrderRepositoryInterface
    {
        $repository = $this->createMock(OrderRepositoryInterface::class);
        $repository->method('save')->willReturnCallback(function (Order $order): Order {
            $this->saveOrder($order);
            return $order;
        });

        return $repository;
    }

    /**
     * Nets out the memos saved so far, as the fresh memo query does.
     */
    private function composeShipment(): ComposeShipment
    {
        $compose = $this->createMock(ComposeShipment::class);
        $compose->method('executeNetOfRefunds')->willReturnCallback(function (Order $order): array {
            $net = (float)$order->getAllVisibleItems()[0]->getData('qtyOrdered') - array_sum($this->savedMemos);

            return ['line_items' => $net > 0 ? [1] : [], 'net' => $net];
        });

        return $compose;
    }

    private function adapter(): Adapter
    {
        $adapter = $this->createMock(Adapter::class);
        $adapter->method('execute')->willReturnCallback(function (string $endpoint, array $payload = []): array {
            if ($this->twoFails !== null) {
                throw $this->twoFails;
            }
            $this->sent[] = $payload;
            return ['fulfilled_order' => ['id' => 'fulfilled-id']];
        });

        return $adapter;
    }

    /**
     * The merchant invoiced everything offline, so the plugin's own invoice
     * totals zero and is not created (FulfilmentInvoiceTest covers the rest).
     */
    private function invoiceService(): InvoiceService
    {
        $service = $this->getMockBuilder(InvoiceService::class)
            ->disableOriginalConstructor()
            ->addMethods(['prepareInvoice'])
            ->getMock();
        $service->method('prepareInvoice')->willReturnCallback(static function (): Invoice {
            $invoice = new Invoice();
            $invoice->setData('grand_total', 0.0);
            return $invoice;
        });

        return $service;
    }

    private function historyFactory(): HistoryFactory
    {
        $factory = $this->getMockBuilder(HistoryFactory::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['create'])
            ->getMock();
        $factory->method('create')->willReturnCallback(static fn () => new \Magento\Sales\Model\Order\Status\History());

        return $factory;
    }

    private function historyRepository(): OrderStatusHistoryRepositoryInterface
    {
        $repository = $this->createMock(OrderStatusHistoryRepositoryInterface::class);
        $repository->method('save')->willReturnCallback(function ($history) {
            $comment = (string)$history->getComment();
            if (strpos($comment, 'marked as') === false) {
                $this->comments[] = $comment;
            }
            return $history;
        });

        return $repository;
    }

    private function messageManager(): MessageManager
    {
        $manager = $this->createMock(MessageManager::class);
        $manager->method('addErrorMessage')->willReturnCallback(function ($message) use ($manager) {
            $this->adminMessages[] = (string)$message;
            return $manager;
        });

        return $manager;
    }

    private function logRepository(): LogRepository
    {
        $log = $this->createMock(LogRepository::class);
        $log->method('addErrorLog')->willReturnCallback(function (string $type, $data): void {
            $this->errors[] = [$type, $data];
        });

        return $log;
    }
}

class DeferralSaveEvent extends \Magento\Framework\Event\Observer
{
    /** @var DataObject */
    private $event;

    public function __construct(DataObject $event)
    {
        $this->event = $event;
    }

    public function getEvent(): DataObject
    {
        return $this->event;
    }
}

class DeferralMethodInstance
{
    /**
     * @param mixed $response
     * @return null
     */
    public function getErrorFromResponse($response)
    {
        return null;
    }
}
