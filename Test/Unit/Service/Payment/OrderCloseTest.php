<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Service\Payment;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\DataObject;
use Magento\Framework\Event\Observer;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Sales\Api\OrderManagementInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\BrandOverlayRegistryInterface;
use Two\Gateway\Api\BrandRegistryInterface;
use Two\Gateway\Api\Log\RepositoryInterface as LogRepository;
use Two\Gateway\Model\Two;
use Two\Gateway\Observer\SalesOrderCancelAfter;
use Two\Gateway\Service\Payment\OrderService;
use Two\Gateway\Service\Payment\RestoreQuote;

/**
 * TWO-27389: an order is closed through Magento's own cancel service, which
 * returns its stock and coupon usage, and a cart comes back only to the
 * browser that placed the order, without returning stock a second time and
 * without the declined payment method chosen.
 */
class OrderCloseTest extends TestCase
{
    /** @var string[] */
    private $calls = [];

    /** @var int units returned to stock by Magento's cancel */
    private $stockReturned = 0;

    /** @var array[] the cancelled events integrations heard */
    private $events = [];

    public static function closeCases(): array
    {
        return [
            'Two cancelled it: closed, nothing sent back' => ['two', null, 'canceled', 'two'],
            'the buyer left verification: closed after the cancel at Two' => ['buyer', null, 'canceled', 'buyer'],
            'a failed return: closed as failed' => ['shop', Two::STATUS_FAILED, Two::STATUS_FAILED, 'shop'],
        ];
    }

    #[DataProvider('closeCases')]
    public function testAnOrderIsReadAfreshThenClosedThroughMagentosCancelService(
        string $cancelledBy,
        ?string $status,
        string $expectedStatus,
        string $expectedFlag
    ): void {
        $order = $this->order();
        $order->setData(OrderService::PENDING_CHECKED_AT, '2026-11-24 10:00:00');
        $managed = $this->order();
        $service = $this->service($managed, true);

        $service->cancelLocally($order, 'why', $cancelledBy, $status);

        $description = $this->dataName();
        $this->assertSame(['load', 'cancel', 'addOrderComment why', 'save'], $this->calls, "$description: actions");
        $this->assertSame($expectedFlag, $managed->getData(OrderService::SKIP_CANCEL_SYNC), "$description: no cancel echoed to Two");
        $this->assertSame([Order::STATE_CANCELED, $expectedStatus], [$managed->getState(), $managed->getStatus()], "$description: closed");
        $this->assertSame('2026-11-24 10:00:00', $managed->getData(OrderService::PENDING_CHECKED_AT), "$description: check time kept");
    }

    public function testAnInstanceLoadedBeforeTheLockIsNotCancelledAsItWas(): void
    {
        // The admin's button loads the order through the repository, then
        // waits for the lock; another request changes it meanwhile.
        $managed = $this->order()->setTwoOrderId('read before the lock');
        $service = $this->service($managed, true);
        $seen = null;
        (new \ReflectionProperty(OrderService::class, 'orderManagement'))->setValue(
            $service,
            $this->management($managed, true, function () use ($managed, &$seen) {
                $seen = $managed->getTwoOrderId();
            })
        );

        $service->cancelLocally($this->order(), 'why');

        $this->assertSame('two-1', $seen, 'cancelled as the database holds it');
    }

    public static function historyCases(): array
    {
        return [
            'the caller holds its own copy' => [false],
            'the caller holds the repository\'s instance' => [true],
        ];
    }

    #[DataProvider('historyCases')]
    public function testHistoryTheCallerAddedIsSavedWithTheClosedOrderOnce(bool $sameInstance): void
    {
        $order = $this->order();
        $unsaved = new DataObject(['comment' => 'Two declined this order.', 'status' => Two::STATUS_PENDING]);
        $saved = new DataObject(['id' => 5, 'comment' => 'placed', 'status' => Two::STATUS_PENDING]);
        $order->setData('status_histories', [$saved, $unsaved]);
        $managed = $sameInstance ? $order : $this->order();
        $service = $this->service($managed, true);

        $service->failOrder($order, 'why');

        $description = $this->dataName();
        $this->assertSame([$unsaved], $managed->getData('status_histories'), "$description: only the unsaved entry, once");
        $this->assertSame(Two::STATUS_FAILED, $managed->getStatus(), $description);
    }

    public function testAnOrderMagentoWillNotCancelIsNotSaved(): void
    {
        $managed = $this->order();
        $service = $this->service($managed, false);

        try {
            $service->cancelLocally($this->order(), 'why');
            $this->fail('a refused cancel must throw');
        } catch (LocalizedException $exception) {
            $this->assertSame(['load', 'cancel'], $this->calls, 'nothing written');
            $this->assertSame(Order::STATE_PENDING_PAYMENT, $managed->getState());
        }
    }

    public function testAFailedReturnReturnsStockExactlyOnce(): void
    {
        $order = $this->order();
        $managed = $this->order();
        $service = $this->service($managed, true);
        $restore = $this->createMock(RestoreQuote::class);
        $restore->expects($this->once())->method('execute')->with($order)->willReturnCallback(function () {
            $this->calls[] = 'restoreQuote';
            return true;
        });
        (new \ReflectionProperty(OrderService::class, 'restoreQuote'))->setValue($service, $restore);

        $shown = $service->failBuyerReturn($order, new LocalizedException(__('For the buyer.')));

        $this->assertSame('For the buyer.', $shown);
        $this->assertSame(['restoreQuote', 'load', 'cancel', 'addOrderComment For the buyer.', 'save'], $this->calls);
        $this->assertSame([Order::STATE_CANCELED, Two::STATUS_FAILED], [$managed->getState(), $managed->getStatus()]);
        $this->assertSame(1, $this->stockReturned, 'only Magento\'s cancel returns stock');
        // Restoring the cart has nothing that could return stock.
        $parameters = array_map(
            static fn (\ReflectionParameter $p) => (string)$p->getType(),
            (new \ReflectionMethod(RestoreQuote::class, '__construct'))->getParameters()
        );
        $this->assertSame([CheckoutSession::class, CartRepositoryInterface::class], $parameters);
    }

    public static function chainCases(): array
    {
        return [
            'Two cancelled it' => ['two'],
            'the buyer left verification' => ['buyer'],
            'a failed return' => ['shop'],
        ];
    }

    /**
     * The flag must reach the order Magento's cancel hands its observers, or
     * the cancel observer sends a cancel to Two as well.
     */
    #[DataProvider('chainCases')]
    public function testTheCancelObserverSendsNothingToTwo(string $cancelledBy): void
    {
        $managed = $this->order()->setPayment((new Order\Payment())->setMethod('two_payment'));
        $service = $this->service($managed, true);
        $observer = $this->cancelObserver($service);
        (new \ReflectionProperty(OrderService::class, 'orderManagement'))->setValue(
            $service,
            $this->management($managed, true, function () use ($observer, $managed) {
                // Magento dispatches order_cancel_after with the order it cancelled.
                $observer->execute(new class (new DataObject(['order' => $managed])) extends Observer {
                    private $event;

                    public function __construct($event)
                    {
                        $this->event = $event;
                    }

                    public function getEvent()
                    {
                        return $this->event;
                    }
                });
            })
        );

        $service->cancelLocally($this->order(), 'why', $cancelledBy);

        $description = $this->dataName();
        $this->assertNotContains('cancelTwoOrder', $this->calls, "$description: no cancel sent to Two");
        $this->assertSame([['cancelled_by' => $cancelledBy]], $this->events, "$description: integrations told who");
    }

    public static function restoreCases(): array
    {
        // last order placed in this browser, the quote's reserved order id (null: quote gone),
        // Magento restores it, restored
        return [
            'this browser placed the order: its cart comes back' => ['100000042', '100000042', true, true],
            'Magento could not restore it: nothing' => ['100000042', '100000042', false, false],
            'another order placed since in this browser: nothing' => ['100000043', '100000042', true, false],
            'no order placed in this browser: nothing' => [null, '100000042', true, false],
            'the cart already came back: nothing' => ['100000042', '', true, false],
            'the cart was reserved for another order: nothing' => ['100000042', '100000050', true, false],
            'the cart is gone: nothing' => ['100000042', null, true, false],
        ];
    }

    #[DataProvider('restoreCases')]
    public function testACartComesBackOnlyToTheBrowserThatPlacedTheOrder(
        ?string $lastRealOrderId,
        ?string $reservedOrderId,
        bool $magentoRestores,
        bool $expected
    ): void {
        // Models core: the payment carries the method the quote's save writes,
        // and removePayment() only flags the loaded payment, which that save
        // never writes, so the stored method would survive it (TWO-27396).
        $restored = new class {
            public $payment;

            public function __construct()
            {
                $this->payment = new DataObject(['method' => 'two_payment']);
            }

            public function getPayment()
            {
                return $this->payment;
            }

            public function removePayment()
            {
                $this->payment->setData('is_deleted', true);
                return $this;
            }
        };
        $session = $this->getMockBuilder(CheckoutSession::class)->onlyMethods(['restoreQuote'])->getMock();
        $session->setLastRealOrderId($lastRealOrderId);
        $session->setQuote($restored);
        $shouldRestore = $expected || !$magentoRestores;
        $session->expects($shouldRestore ? $this->once() : $this->never())->method('restoreQuote')->willReturn($magentoRestores);
        $carts = new class ($this, $reservedOrderId) implements CartRepositoryInterface {
            public $saved = [];
            private $test;
            private $reserved;

            public function __construct(TestCase $test, ?string $reserved)
            {
                $this->test = $test;
                $this->reserved = $reserved;
            }

            public function get($id)
            {
                $this->test->assertSame(7, $id, 'the order\'s own quote');
                if ($this->reserved === null) {
                    throw new NoSuchEntityException(__('gone'));
                }
                return new DataObject(['reservedOrderId' => $this->reserved === '' ? null : $this->reserved]);
            }

            public function save($quote)
            {
                $this->saved[] = $quote->getPayment()->getMethod();
            }
        };
        $result = (new RestoreQuote($session, $carts))->execute($this->order());

        $description = $this->dataName();
        $this->assertSame($expected, $result, "$description: restored");
        // A restored cart offers every method again with none chosen.
        $this->assertSame($expected ? [null] : [], $carts->saved, "$description: cart saved with no method stored");
    }

    private function order(): Order
    {
        return (new Order())->setState(Order::STATE_PENDING_PAYMENT)->setStatus(Two::STATUS_PENDING)
            ->setId(42)->setIncrementId('100000042')->setQuoteId(7)->setTwoOrderId('two-1');
    }

    /**
     * @param Order $managed the instance Magento's order repository holds
     * @param bool $cancels whether Magento's cancel service cancels it
     */
    private function service(Order $managed, bool $cancels): OrderService
    {
        $service = $this->getMockBuilder(OrderService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['addOrderComment', 'cancelTwoOrder'])
            ->getMock();
        $service->method('addOrderComment')->willReturnCallback(function ($order, $comment) use ($service, $managed) {
            $this->assertSame($managed, $order, 'comments on the closed order');
            $this->calls[] = 'addOrderComment ' . $comment;
            return $service;
        });
        $service->method('cancelTwoOrder')->willReturnCallback(function () {
            $this->calls[] = 'cancelTwoOrder';
            return true;
        });
        $repository = $this->createMock(OrderRepositoryInterface::class);
        $repository->method('get')->with(42)->willReturn($managed);
        $repository->method('save')->willReturnCallback(function ($order) use ($managed) {
            $this->assertSame($managed, $order, 'saves the closed order');
            $this->calls[] = 'save';
            return $order;
        });
        // Reading an order replaces its data with the row as stored.
        $resource = new class ($this) {
            private $test;

            public function __construct($test)
            {
                $this->test = $test;
            }

            public function load($order, $id)
            {
                $this->test->record('load');
                $order->setData('status_histories', null)
                    ->setData(OrderService::PENDING_CHECKED_AT, null)
                    ->setState(Order::STATE_PENDING_PAYMENT)->setStatus(Two::STATUS_PENDING)
                    ->setTwoOrderId('two-1');
                return $this;
            }
        };
        $brand = $this->createMock(BrandRegistryInterface::class);
        $brand->method('getProductName')->willReturn('Two');
        $values = [
            'orderRepository' => $repository,
            'orderResource' => $resource,
            'orderManagement' => $this->management($managed, $cancels),
            'brandRegistry' => $brand,
            'logRepository' => $this->createMock(LogRepository::class),
        ];
        foreach ($values as $property => $value) {
            (new \ReflectionProperty(OrderService::class, $property))->setValue($service, $value);
        }
        return $service;
    }

    /**
     * Magento's cancel service: it cancels the repository's instance, which
     * returns stock, then hands that order to the cancel observers.
     */
    private function management(Order $managed, bool $cancels, ?callable $observers = null): OrderManagementInterface
    {
        $management = $this->createMock(OrderManagementInterface::class);
        $management->method('cancel')->willReturnCallback(function ($id) use ($managed, $cancels, $observers) {
            $this->assertSame(42, $id);
            $this->calls[] = 'cancel';
            if (!$cancels) {
                return false;
            }
            if ($observers !== null) {
                $observers();
            }
            $this->stockReturned++;
            $managed->setState(Order::STATE_CANCELED)->setStatus('canceled');
            return true;
        });
        return $management;
    }

    private function cancelObserver(OrderService $service): SalesOrderCancelAfter
    {
        $overlay = $this->createMock(BrandOverlayRegistryInterface::class);
        $overlay->method('isTwoStackMethod')->willReturn(true);
        $brand = $this->createMock(BrandRegistryInterface::class);
        $brand->method('getProductName')->willReturn('Two');
        $lifecycle = new class ($this) {
            private $test;

            public function __construct($test)
            {
                $this->test = $test;
            }

            public function dispatchCancelled($order, array $twoData = [])
            {
                $this->test->recordEvent($twoData);
            }
        };
        $reflection = new \ReflectionClass(SalesOrderCancelAfter::class);
        $observer = $reflection->newInstanceWithoutConstructor();
        $values = [
            'orderService' => $service,
            'brandRegistry' => $brand,
            'overlayRegistry' => $overlay,
            'lifecycleEvents' => $lifecycle,
        ];
        foreach ($values as $property => $value) {
            $reflection->getProperty($property)->setValue($observer, $value);
        }
        return $observer;
    }

    public function record(string $call): void
    {
        $this->calls[] = $call;
    }

    public function recordEvent(array $twoData): void
    {
        $this->events[] = $twoData;
    }
}
