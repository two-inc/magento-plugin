<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Service\Order;

use Magento\Framework\App\State;
use Magento\Framework\DB\TransactionFactory;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Message\ManagerInterface as MessageManager;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Api\OrderStatusHistoryRepositoryInterface;
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
use Two\Gateway\Service\Api\Adapter;
use Two\Gateway\Service\Order\ComposeShipment;
use Two\Gateway\Service\Order\FulfilmentAttempts;
use Two\Gateway\Service\Order\OrderPostprocessor;
use Two\Gateway\Service\Order\StatusFulfilment;

/**
 * TWO-26302: a fulfil-on status is fulfilled after the outermost commit on the
 * sales connection, from the order and credit memos as saved, or at once when
 * no transaction is open. A failure is commented, not thrown.
 *
 * A small shop stands in for core: saving the order persists it and fires
 * sales_order_save_after inside the save's own transaction, the composer reads
 * the credit memos saved so far, and a fresh load reads what was persisted.
 * Core's refund routes hold one transaction across the order and memo saves.
 */
class StatusFulfilmentAfterCommitTest extends TestCase
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

    /** @var int calls to Two, failed ones included */
    private $twoCalls = 0;

    /** @var float what the plugin's own invoice totals */
    private $leftToInvoice = 0.0;

    /** @var \Throwable|null what the fulfilment's own order save throws */
    private $orderSaveFails;

    /** @var FakeOrderResource */
    private $resource;

    /** @var SalesOrderSaveAfter */
    private $observer;

    protected function setUp(): void
    {
        $this->resource = new FakeOrderResource();
        $this->newRequest();
    }

    /**
     * @dataProvider refundRoutes
     */
    public function testARefundFulfilsOnceAfterItsCommitFromSavedState(string $route, string $description): void
    {
        $order = $this->order(['status' => 'processing', 'items' => [[2, 1, 0]]]);
        $this->persist($order);

        $this->refund($route, $order, 1.0);

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
            ['rest', 'REST refund (order then memo, inside the order lock\'s transaction)'],
            ['admin', 'admin credit memo (memo then order, inside one transaction)'],
        ];
    }

    public function testTheObserverRegistersACommitCallbackAndSendsNothingInline(): void
    {
        $order = $this->order(['status' => 'complete', 'items' => [[1, 1, 0]]]);
        $this->persist($order);
        $this->resource->beginTransaction();

        $this->observer->execute($this->saveEvent($order));

        $this->assertSame([], $this->sent, 'nothing sent inside the transaction');
        $this->assertCount(1, $this->resource->callbacks, 'one commit callback');
    }

    public function testAnOrderNotDueSavesWithoutCheckOrCallback(): void
    {
        $order = $this->order(['status' => 'processing', 'items' => [[1, 0, 0]]]);
        $this->resource->beginTransaction();

        $this->observer->execute($this->saveEvent($order));

        $this->assertSame([], $this->resource->callbacks, 'no commit callback');
    }

    public function testTheWholeOrderCheckStillRefusesTheSave(): void
    {
        $order = $this->order(['status' => 'complete', 'items' => [[1, 1, 0], [1, 0, 0]]]);
        $this->resource->beginTransaction();

        try {
            $this->observer->execute($this->saveEvent($order));
            $this->fail('the unshipped item refuses the save');
        } catch (LocalizedException $e) {
            $this->assertSame([], $this->resource->callbacks, 'a refused save registers nothing');
        }
    }

    public function testARefundThatRollsBackFulfilsNothing(): void
    {
        $order = $this->order(['status' => 'processing', 'items' => [[2, 2, 0]]]);
        $this->resource->beginTransaction();
        $order->setData('status', 'complete');
        $this->saveOrder($order);
        // The memo save fails, and the order lock rolls the whole refund back.
        $this->resource->rollBack();

        $this->saveMemo(1.0);

        $this->assertSame(0, $this->resource->level, 'the later save committed');
        $this->assertSame([], $this->sent, 'the rolled-back refund is not fulfilled by a later commit');
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

        $this->refund('rest', $order, 1.0);

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
        $this->refund('rest', $order, 1.0);

        // The in-memory order never saw the marker. Where its payment was
        // changed, its save writes the payment back without the marker, so a
        // fresh load cannot see it either; the test's save always does.
        $this->saveOrder($order);

        $this->assertCount(1, $this->sent, 'one fulfilment');
    }

    public function testTwoSavesInOneTransactionFulfilOnce(): void
    {
        $order = $this->order(['status' => 'complete', 'items' => [[1, 1, 0]]]);
        $this->resource->beginTransaction();
        $this->saveOrder($order);
        $this->saveOrder($order);
        $this->resource->commit();

        $this->assertCount(1, $this->sent, 'one fulfilment');
        $this->assertSame(2 + 1, $this->orderSaves, 'the two saves and the fulfilment\'s own');
    }

    /**
     * @dataProvider secondSaves
     */
    public function testAFailedFulfilmentIsNotSentAgainInTheSameRequest(string $secondSave, string $description): void
    {
        $this->twoFails = new \RuntimeException('timeout');
        $order = $this->order(['status' => 'processing', 'items' => [[2, 2, 0]]]);
        $this->persist($order);

        $this->resource->beginTransaction();
        $order->setData('status', 'complete');
        $this->saveOrder($order);
        if ($secondSave === 'same transaction') {
            $this->saveOrder($order);
        }
        $this->resource->commit();
        if ($secondSave === 'stale copy') {
            $this->saveOrder($order);
        }

        $this->assertSame(1, $this->twoCalls, $description . ': one call to Two');
        $this->assertSame(
            ['Failed to fulfil order with Two. Reason: timeout'],
            $this->comments,
            $description . ': one failure comment'
        );
    }

    public static function secondSaves(): array
    {
        return [
            ['same transaction', 'saved twice in one transaction, so two callbacks'],
            ['stale copy', 'the stale copy saved again after the commit'],
        ];
    }

    /**
     * @dataProvider orderSaves
     */
    public function testTheInvoiceAndTheOrderPersistTogether(
        ?\Throwable $orderSaveFails,
        array $expectedRows,
        array $expectedComments,
        array $expectedLogs,
        bool $expectedMarker,
        string $description
    ): void {
        $this->leftToInvoice = 100.0;
        $this->orderSaveFails = $orderSaveFails;
        $order = $this->order(['status' => 'processing', 'items' => [[1, 1, 0]]]);
        $this->persist($order);

        $order->setData('status', 'complete');
        $this->saveOrder($order);

        $this->assertSame(1, $this->twoCalls, $description . ': one call to Two');
        $this->assertSame($expectedRows, $this->resource->rows, $description . ': rows committed');
        $this->assertSame($expectedComments, $this->comments, $description . ': failure comments');
        $this->assertSame($expectedLogs, array_column($this->errors, 0), $description . ': error logs');
        $this->assertSame(
            $expectedMarker,
            !empty($this->saved['info']['marked_completed']),
            $description . ': marker saved'
        );
        $this->assertSame(0, $this->resource->level, $description . ': transactions balanced');
    }

    public static function orderSaves(): array
    {
        $notSaved = 'Two fulfilled the order, but the order could not be saved. Reason: Deadlock found';

        return [
            [null, ['Two order marked as completed.', 'invoice'], [], [], true, 'the order saves'],
            [
                new \RuntimeException('Deadlock found'),
                [$notSaved],
                [$notSaved],
                ['StatusFulfilmentNotSaved'],
                false,
                'the order save fails after Two accepted: no orphan invoice or completion comment',
            ],
        ];
    }

    public function testAnEventWithoutAnOrderIsIgnored(): void
    {
        $this->resource->beginTransaction();

        $this->observer->execute(new AfterCommitSaveEvent(new DataObject(['order' => null])));

        $this->assertSame([], $this->resource->callbacks, 'no commit callback');
    }

    /**
     * @dataProvider changedBeforeCommit
     */
    public function testTheCallbackRechecksTheSavedOrder(
        array $savedBeforeCommit,
        int $expectedSent,
        array $expectedComments,
        string $description
    ): void {
        $order = $this->order(['status' => 'complete', 'items' => [[2, 2, 0]]]);
        $this->resource->beginTransaction();
        $this->saveOrder($order);
        $this->saved = $savedBeforeCommit + $this->saved;
        $this->resource->commit();

        $this->assertCount($expectedSent, $this->sent, $description . ': fulfilments');
        $this->assertSame($expectedComments, $this->comments, $description . ': comments');
    }

    public static function changedBeforeCommit(): array
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

        $this->refund('rest', $order, 1.0);

        $this->assertSame([], $this->sent, 'nothing left to fulfil');
        $this->assertSame(1, $this->orderSaves, 'only the refund\'s own order save');
        $this->assertSame([], $this->comments, 'no comment');
        $this->assertSame([], $this->errors, 'nothing logged');
    }

    public function testOnlyTheOutermostCommitFulfils(): void
    {
        $order = $this->order(['status' => 'processing', 'items' => [[2, 1, 0]]]);
        $this->persist($order);
        $this->resource->beginTransaction();

        $this->refund('rest', $order, 1.0);
        $sentBeforeOuterCommit = count($this->sent);
        $this->resource->commit();

        $this->assertSame(0, $sentBeforeOuterCommit, 'nothing sent when the refund\'s own transaction commits');
        $this->assertCount(1, $this->sent, 'sent after the outer commit');
        $this->assertSame(1.0, $this->sent[0]['partial']['net'], 'from saved state');
    }

    /**
     * @dataProvider transactions
     */
    public function testAStatusChange(bool $inTransaction, ?bool $commits, int $expectedSent, string $description): void
    {
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
        $this->assertSame([], $this->resource->callbacks, $description . ': nothing left waiting on a commit');
        $this->assertCount($expectedSent, $this->sent, $description . ': fulfilments');
        if ($expectedSent) {
            $this->assertNotSame($order, $this->composedFrom[0], $description . ': composed from a fresh load');
            $this->assertTrue(!empty($this->saved['info']['marked_completed']), $description . ': the marker is saved');
        }
    }

    public static function transactions(): array
    {
        return [
            [false, null, 1, 'no transaction open: fulfilled at once'],
            [true, true, 1, 'inside a transaction that commits: fulfilled after the commit'],
            [true, false, 0, 'inside a transaction that rolls back: nothing sent'],
        ];
    }

    /**
     * Refunds the first item, saving the order and the memo in the order core
     * saves them, inside one transaction: the REST routes save the order
     * first, the admin credit memo the memo first.
     */
    private function refund(string $route, Order $order, float $qty): void
    {
        $this->resource->beginTransaction();
        if ($route === 'admin') {
            $this->saveMemo($qty);
        }
        $item = $order->getAllVisibleItems()[0];
        $item->setData('qtyRefunded', $item->getData('qtyRefunded') + $qty);
        $order->setData('status', 'complete');
        $this->saveOrder($order);
        if ($route !== 'admin') {
            $this->saveMemo($qty);
        }
        $this->resource->commit();
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

        $service = $this->buildStatusFulfilment([
            'configRepository' => $config,
            'brandRegistry' => $brand,
            'overlayRegistry' => $overlay,
            'apiAdapter' => $this->adapter(),
            'orderPostprocessor' => $postprocessor,
            'composeShipment' => $this->composeShipment(),
            'historyFactory' => $this->historyFactory(),
            'invoiceService' => $this->invoiceService(),
            'transactionFactory' => $this->transactionFactory(),
            'orderStatusHistoryRepository' => $this->historyRepository(),
            'attempts' => new FulfilmentAttempts(),
            'orderFactory' => $this->orderFactory(),
            'orderRepository' => $this->orderRepository(),
            'orderResource' => $this->resource,
            'messageManager' => $this->messageManager(),
            'appState' => $state,
            'logRepository' => $this->logRepository(),
        ]);
        $this->observer = new SalesOrderSaveAfter($service);
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
        $payment->setData('method_instance', new AfterCommitMethodInstance());
        $payment->setData('additional_information', $state['info'] ?? []);
        $order->setData('payment', $payment);

        return $order;
    }

    private function saveEvent(Order $order): AfterCommitSaveEvent
    {
        return new AfterCommitSaveEvent(new DataObject(['order' => $order]));
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
            if ($this->orderSaveFails !== null) {
                // Core's save rolls its own transaction back and rethrows.
                $this->resource->beginTransaction();
                $this->resource->rollBack();
                throw $this->orderSaveFails;
            }
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
            $this->twoCalls++;
            if ($this->twoFails !== null) {
                throw $this->twoFails;
            }
            $this->sent[] = $payload;
            return ['fulfilled_order' => ['id' => 'fulfilled-id']];
        });

        return $adapter;
    }

    /**
     * By default the merchant invoiced everything offline, so the plugin's own
     * invoice totals zero and is not created (FulfilmentInvoiceTest covers the
     * rest); $leftToInvoice makes it create one.
     */
    private function invoiceService(): InvoiceService
    {
        $service = $this->getMockBuilder(InvoiceService::class)
            ->disableOriginalConstructor()
            ->addMethods(['prepareInvoice'])
            ->getMock();
        $service->method('prepareInvoice')->willReturnCallback(function (): Invoice {
            $invoice = new Invoice();
            $invoice->setData('grand_total', $this->leftToInvoice);
            return $invoice;
        });

        return $service;
    }

    /**
     * Saving the invoice writes its row on the sales connection.
     */
    private function transactionFactory(): TransactionFactory
    {
        $resource = $this->resource;
        $transaction = new class ($resource) {
            /** @var FakeOrderResource */
            private $resource;

            public function __construct(FakeOrderResource $resource)
            {
                $this->resource = $resource;
            }

            public function addObject($object): self
            {
                return $this;
            }

            public function save(): self
            {
                $this->resource->write('invoice');
                return $this;
            }
        };
        $factory = $this->getMockBuilder(TransactionFactory::class)
            ->disableOriginalConstructor()
            ->addMethods(['create'])
            ->getMock();
        $factory->method('create')->willReturn($transaction);

        return $factory;
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
            $this->resource->write($comment);
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

class AfterCommitSaveEvent extends \Magento\Framework\Event\Observer
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

class AfterCommitMethodInstance
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
