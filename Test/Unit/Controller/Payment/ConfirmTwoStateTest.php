<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Controller\Payment;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\DataObject;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Framework\Message\ManagerInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\BrandRegistryInterface;
use Two\Gateway\Api\Log\RepositoryInterface as LogRepository;
use Two\Gateway\Controller\Payment\Confirm;
use Two\Gateway\Model\Two;
use Two\Gateway\Service\Payment\OrderService;
use Two\Gateway\Service\Payment\PendingOrderResolver;

/**
 * TWO-26300: what the buyer's confirm return does for each state Two reports.
 * A return to an order Two has cancelled restores the cart and fails the order;
 * it never reaches the success page.
 */
class ConfirmTwoStateTest extends TestCase
{
    private const CANCELLED = 'Your invoice purchase with Two has been cancelled. The cart will be restored.';
    private const NOT_PROCESSED = 'Your invoice purchase with Two could not be processed. The cart will be restored.';

    /** @var string[] */
    private $calls = [];

    /** @var string[] */
    private $shown = [];

    /** @var string[] */
    private $redirects = [];

    public static function cases(): array
    {
        $success = 'checkout/onepage/success';
        $cart = 'checkout/cart';
        $process = ['getTwoOrderFromApi', 'send', 'processOrder'];
        $fail = ['restoreQuote', 'failOrder'];
        return [
            'VERIFIED is confirmed with Two and processed' =>
                [['state' => 'VERIFIED'], ['getTwoOrderFromApi', 'confirmOrder', 'send', 'processOrder'], $success, []],
            'CONFIRMED is processed without a second confirm' =>
                [['state' => 'CONFIRMED'], $process, $success, []],
            'FULFILLED is processed without a confirm' =>
                [['state' => 'FULFILLED'], $process, $success, []],
            'CANCELLED restores the cart and fails the order' =>
                [['state' => 'CANCELLED'], array_merge(['getTwoOrderFromApi', 'addOrderComment'], $fail), $cart, [self::CANCELLED]],
            'a REJECTED status restores the cart and fails the order' =>
                [['state' => 'UNVERIFIED', 'status' => 'REJECTED'], array_merge(['getTwoOrderFromApi', 'addOrderComment'], $fail), $cart, [self::CANCELLED]],
            'UNVERIFIED restores the cart and fails the order' =>
                [['state' => 'UNVERIFIED'], array_merge(['getTwoOrderFromApi', 'addOrderComment'], $fail), $cart, [self::NOT_PROCESSED]],
            'REFUNDED restores the cart and fails the order' =>
                [['state' => 'REFUNDED'], array_merge(['getTwoOrderFromApi', 'addOrderComment'], $fail), $cart, [self::NOT_PROCESSED]],
        ];
    }

    #[DataProvider('cases')]
    public function testTheReturnActsOnTheStateTwoReports(
        array $twoOrder,
        array $expectedCalls,
        string $expectedRedirect,
        array $expectedShown
    ): void {
        $order = (new Order())->setState(Order::STATE_PENDING_PAYMENT)->setStatus(Two::STATUS_PENDING)
            ->setId(42)->setIncrementId('100000042')->setQuoteId(7)->setTwoOrderId('two-1')
            ->setBillingAddress(new DataObject());
        $service = $this->orderService($order, $twoOrder + ['id' => 'two-1']);

        $this->controller($service)->execute();

        $description = $this->dataName();
        $this->assertSame($expectedCalls, $this->calls, "$description: actions");
        $this->assertSame($expectedShown, $this->shown, "$description: buyer message");
        $this->assertSame(['https://shop.test/' . $expectedRedirect], $this->redirects, "$description: redirect");
    }

    private function orderService(Order $order, array $twoOrder): OrderService
    {
        $recorded = [
            'getTwoOrderFromApi', 'confirmOrder', 'processOrder', 'restoreQuote', 'failOrder', 'addOrderComment',
            'cancelLocally',
        ];
        $recorded = array_values(array_filter($recorded, static fn ($m) => method_exists(OrderService::class, $m)));
        $passThrough = array_values(array_filter(
            ['reloadOrder'],
            static fn ($m) => method_exists(OrderService::class, $m)
        ));
        $service = $this->getMockBuilder(OrderService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(array_merge(['getOrderByReference'], $recorded, $passThrough))
            ->getMock();
        $service->method('getOrderByReference')->willReturn($order);
        foreach ($passThrough as $method) {
            $service->method($method)->willReturn($order);
        }
        foreach ($recorded as $method) {
            $service->method($method)->willReturnCallback(function () use ($method, $service, $twoOrder) {
                $this->calls[] = $method;
                if ($method === 'getTwoOrderFromApi' || $method === 'confirmOrder') {
                    return $twoOrder;
                }
                return $service;
            });
        }
        $brand = $this->brand();
        $values = [
            'brandRegistry' => $brand,
            'logRepository' => $this->createMock(LogRepository::class),
            'orderRepository' => $this->createMock(OrderRepositoryInterface::class),
        ];
        foreach ($values as $property => $value) {
            (new \ReflectionProperty(OrderService::class, $property))->setValue($service, $value);
        }

        return $service;
    }

    private function brand(): BrandRegistryInterface
    {
        $brand = $this->createMock(BrandRegistryInterface::class);
        $brand->method('getProductName')->willReturn('Two');
        return $brand;
    }

    /**
     * Stands in for OrderSender, whose send() the catch-all stub lacks.
     */
    private function sender(): object
    {
        $test = $this;
        return new class ($test) {
            private $test;

            public function __construct($test)
            {
                $this->test = $test;
            }

            public function send($order)
            {
                $this->test->record('send');
                return true;
            }
        };
    }

    public function record(string $call): void
    {
        $this->calls[] = $call;
    }

    private function controller(OrderService $service): Confirm
    {
        $reflection = new \ReflectionClass(Confirm::class);
        $controller = $reflection->newInstanceWithoutConstructor();

        $messages = $this->createMock(ManagerInterface::class);
        $messages->method('addErrorMessage')->willReturnCallback(function ($message) use ($messages) {
            $this->shown[] = (string)$message;
            return $messages;
        });
        $url = new class {
            public function getUrl($path = null, $params = null)
            {
                return 'https://shop.test/' . $path;
            }
        };
        $test = $this;
        $response = new class ($test) {
            private $test;

            public function __construct($test)
            {
                $this->test = $test;
            }

            public function setRedirect($url)
            {
                $this->test->recordRedirect((string)$url);
                return $this;
            }
        };
        $sender = $this->sender();
        $values = [
            'orderService' => $service,
            'orderSender' => $sender,
            'checkoutSession' => new CheckoutSession(),
            'brandRegistry' => $this->brand(),
            'messageManager' => $messages,
            '_url' => $url,
            '_response' => $response,
        ];
        if (class_exists(PendingOrderResolver::class)) {
            $values['pendingOrderResolver'] = $this->resolver($service, $sender);
        }
        foreach ($values as $property => $value) {
            $owner = $reflection;
            while (!$owner->hasProperty($property) && $owner->getParentClass()) {
                $owner = $owner->getParentClass();
            }
            if ($owner->hasProperty($property)) {
                $owner->getProperty($property)->setValue($controller, $value);
            }
        }

        return $controller;
    }

    private function resolver(OrderService $service, object $sender): object
    {
        $lock = $this->createMock(LockManagerInterface::class);
        $lock->method('lock')->willReturn(true);
        $reflection = new \ReflectionClass(PendingOrderResolver::class);
        $resolver = $reflection->newInstanceWithoutConstructor();
        $values = [
            'orderService' => $service,
            'orderSender' => $sender,
            'lockManager' => $lock,
            'orderRepository' => $this->createMock(OrderRepositoryInterface::class),
            'brandRegistry' => $this->brand(),
            'logRepository' => $this->createMock(LogRepository::class),
        ];
        foreach ($values as $property => $value) {
            if ($reflection->hasProperty($property)) {
                $reflection->getProperty($property)->setValue($resolver, $value);
            }
        }
        return $resolver;
    }

    public function recordRedirect(string $url): void
    {
        $this->redirects[] = $url;
    }
}
