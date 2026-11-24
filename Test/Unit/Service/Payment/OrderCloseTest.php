<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Service\Payment;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Sales\Api\OrderManagementInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\BrandRegistryInterface;
use Two\Gateway\Api\Log\RepositoryInterface as LogRepository;
use Two\Gateway\Model\Two;
use Two\Gateway\Service\Payment\OrderService;
use Two\Gateway\Service\Payment\RestoreQuote;

/**
 * TWO-27389: an order is closed through Magento's own cancel service, which
 * returns its stock and coupon usage, and a cart comes back only to the
 * browser that placed the order, without returning stock a second time.
 */
class OrderCloseTest extends TestCase
{
    /** @var string[] */
    private $calls = [];

    /** @var int units returned to stock by Magento's cancel */
    private $stockReturned = 0;

    public static function closeCases(): array
    {
        return [
            'Two cancelled it: closed, nothing sent back' => ['two', null, 'canceled', 'two'],
            'the buyer left verification: closed after the cancel at Two' => ['buyer', null, 'canceled', 'buyer'],
            'a failed return: closed as failed' => ['shop', Two::STATUS_FAILED, Two::STATUS_FAILED, 'shop'],
        ];
    }

    #[DataProvider('closeCases')]
    public function testAnOrderIsClosedThroughMagentosCancelService(
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
        $this->assertSame(['cancel', 'addOrderComment why', 'save'], $this->calls, "$description: actions");
        $this->assertSame($expectedFlag, $managed->getData(OrderService::SKIP_CANCEL_SYNC), "$description: no cancel echoed to Two");
        $this->assertSame([Order::STATE_CANCELED, $expectedStatus], [$managed->getState(), $managed->getStatus()], "$description: closed");
        $this->assertSame('2026-11-24 10:00:00', $managed->getData(OrderService::PENDING_CHECKED_AT), "$description: check time kept");
    }

    public function testAnOrderMagentoWillNotCancelIsNotSaved(): void
    {
        $managed = $this->order();
        $service = $this->service($managed, false);

        try {
            $service->cancelLocally($this->order(), 'why');
            $this->fail('a refused cancel must throw');
        } catch (LocalizedException $exception) {
            $this->assertSame(['cancel'], $this->calls, 'nothing written');
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
        $this->assertSame(['restoreQuote', 'cancel', 'addOrderComment For the buyer.', 'save'], $this->calls);
        $this->assertSame([Order::STATE_CANCELED, Two::STATUS_FAILED], [$managed->getState(), $managed->getStatus()]);
        $this->assertSame(1, $this->stockReturned, 'only Magento\'s cancel returns stock');
        // Restoring the cart returns nothing to stock itself.
        $parameters = array_map(
            static fn (\ReflectionParameter $p) => (string)$p->getType(),
            (new \ReflectionMethod(RestoreQuote::class, '__construct'))->getParameters()
        );
        $this->assertSame([CheckoutSession::class, CartRepositoryInterface::class], $parameters);
    }

    public function testAReturnWithNoOrderRestoresNothing(): void
    {
        $service = $this->service($this->order(), true);
        $restore = $this->createMock(RestoreQuote::class);
        $restore->expects($this->never())->method('execute');
        (new \ReflectionProperty(OrderService::class, 'restoreQuote'))->setValue($service, $restore);

        $service->failBuyerReturn(null, new LocalizedException(__('For the buyer.')));

        $this->assertSame([], $this->calls);
    }

    public static function restoreCases(): array
    {
        // last order placed in this browser, the quote's reserved order id (null: quote gone), restored
        return [
            'this browser placed the order: its cart comes back' => ['100000042', '100000042', true],
            'another order placed since in this browser: nothing' => ['100000043', '100000042', false],
            'no order placed in this browser: nothing' => [null, '100000042', false],
            'the cart already came back: nothing' => ['100000042', '', false],
            'the cart was reserved for another order: nothing' => ['100000042', '100000050', false],
            'the cart is gone: nothing' => ['100000042', null, false],
        ];
    }

    #[DataProvider('restoreCases')]
    public function testACartComesBackOnlyToTheBrowserThatPlacedTheOrder(
        ?string $lastRealOrderId,
        ?string $reservedOrderId,
        bool $expected
    ): void {
        $session = $this->getMockBuilder(CheckoutSession::class)->onlyMethods(['restoreQuote'])->getMock();
        $session->setLastRealOrderId($lastRealOrderId);
        $session->expects($expected ? $this->once() : $this->never())->method('restoreQuote')->willReturn(true);
        $carts = new class ($this, $reservedOrderId) implements CartRepositoryInterface {
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
        };

        $restored = (new RestoreQuote($session, $carts))->execute($this->order());

        $this->assertSame($expected, $restored, $this->dataName());
    }

    private function order(): Order
    {
        return (new Order())->setState(Order::STATE_PENDING_PAYMENT)->setStatus(Two::STATUS_PENDING)
            ->setId(42)->setIncrementId('100000042')->setQuoteId(7);
    }

    /**
     * @param Order $managed the instance Magento's order repository holds
     * @param bool $cancels whether Magento's cancel service cancels it
     */
    private function service(Order $managed, bool $cancels): OrderService
    {
        $service = $this->getMockBuilder(OrderService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['addOrderComment'])
            ->getMock();
        $service->method('addOrderComment')->willReturnCallback(function ($order, $comment) use ($service, $managed) {
            $this->assertSame($managed, $order, 'comments on the closed order');
            $this->calls[] = 'addOrderComment ' . $comment;
            return $service;
        });
        $repository = $this->createMock(OrderRepositoryInterface::class);
        $repository->method('get')->with(42)->willReturn($managed);
        $repository->method('save')->willReturnCallback(function ($order) use ($managed) {
            $this->assertSame($managed, $order, 'saves the closed order');
            $this->calls[] = 'save';
            return $order;
        });
        $management = $this->createMock(OrderManagementInterface::class);
        $management->method('cancel')->willReturnCallback(function ($id) use ($managed, $cancels) {
            $this->assertSame(42, $id);
            $this->calls[] = 'cancel';
            if (!$cancels) {
                return false;
            }
            $this->stockReturned++;
            $managed->setState(Order::STATE_CANCELED)->setStatus('canceled');
            return true;
        });
        $brand = $this->createMock(BrandRegistryInterface::class);
        $brand->method('getProductName')->willReturn('Two');
        $values = [
            'orderRepository' => $repository,
            'orderManagement' => $management,
            'brandRegistry' => $brand,
            'logRepository' => $this->createMock(LogRepository::class),
        ];
        foreach ($values as $property => $value) {
            (new \ReflectionProperty(OrderService::class, $property))->setValue($service, $value);
        }
        return $service;
    }
}
