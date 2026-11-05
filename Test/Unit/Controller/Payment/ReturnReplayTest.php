<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Controller\Payment;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Message\ManagerInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\BrandRegistryInterface;
use Two\Gateway\Api\Log\RepositoryInterface as LogRepository;
use Two\Gateway\Controller\Payment\Cancel;
use Two\Gateway\Controller\Payment\Confirm;
use Two\Gateway\Controller\Payment\Verificationfailed;
use Two\Gateway\Exception\TwoRefusalException;
use Two\Gateway\Model\Two;
use Two\Gateway\Service\Payment\OrderService;

/**
 * TWO-27057: a buyer return link acts only on an order still awaiting the
 * buyer's verification, and a cancel Two did not carry out leaves the order
 * as it is.
 */
class ReturnReplayTest extends TestCase
{
    private const CANCELLED = 'Your invoice purchase with Two has been cancelled. The cart will be restored.';
    private const GENERAL = 'Something went wrong with your request to Two. Please try again later.';
    private const FAILED = 'Your invoice purchase with Two failed verification. The cart will be restored.';

    /** @var string[] what the order service was asked to do, in order */
    private $calls = [];

    /** @var string[] */
    private $shown = [];

    /** @var string[] */
    private $redirects = [];

    public static function cases(): array
    {
        $awaiting = [Order::STATE_PENDING_PAYMENT, Two::STATUS_PENDING];
        $moved = [
            'processing' => [Order::STATE_PROCESSING, Order::STATE_PROCESSING],
            'complete' => [Order::STATE_COMPLETE, Order::STATE_COMPLETE],
            'already failed' => [Order::STATE_CANCELED, Two::STATUS_FAILED],
            'pending state, another status' => [Order::STATE_PENDING_PAYMENT, 'holded'],
        ];
        $refusal = static fn (): \Exception => new TwoRefusalException(__('Two refused: raw account'), __('Cannot cancel.'));
        $transport = static fn (): \Exception => new \Exception('connection reset');

        $rows = [];
        foreach ([Confirm::class, Cancel::class, Verificationfailed::class] as $controller) {
            $name = substr($controller, strrpos($controller, '\\') + 1);
            foreach ($moved as $label => $order) {
                $rows["$name: a $label order is left alone"] = [$controller, $order, null, [], []];
            }
        }
        // A repeated confirm return (a refresh, or the redirect sent twice)
        // finds the order the first request confirmed, so the buyer sees the
        // success page for it again.
        foreach (['processing', 'complete'] as $label) {
            [$state, $status] = $moved[$label];
            $rows["Confirm: a $label order is left alone"] = [
                Confirm::class, $moved[$label], null, [], [], 'checkout/onepage/success', [
                    'last_quote_id' => 7,
                    'last_success_quote_id' => 7,
                    'last_order_id' => 42,
                    'last_real_order_id' => '100000042',
                    'last_order_status' => $status,
                ],
            ];
        }
        $rows += [
            'Cancel: a refused cancel keeps the order and the cart' =>
                [Cancel::class, $awaiting, $refusal, ['cancelTwoOrder', 'addOrderComment', 'save'], ['Cannot cancel.']],
            'Cancel: a cancel with no answer keeps the order and the cart' =>
                [Cancel::class, $awaiting, $transport, ['cancelTwoOrder', 'addOrderComment', 'save'], [self::GENERAL]],
            'Cancel: an accepted cancel fails the order and restores the cart' =>
                [Cancel::class, $awaiting, null, ['cancelTwoOrder', 'restoreQuote', 'failOrder'], [self::CANCELLED]],
            'Verificationfailed: a refused cancel keeps the order and the cart' =>
                [Verificationfailed::class, $awaiting, $refusal, ['cancelTwoOrder', 'addOrderComment', 'save'], ['Cannot cancel.']],
            'Verificationfailed: a cancel with no answer keeps the order and the cart' =>
                [Verificationfailed::class, $awaiting, $transport, ['cancelTwoOrder', 'addOrderComment', 'save'], [self::GENERAL]],
            'Verificationfailed: an accepted cancel fails the order and restores the cart' =>
                [Verificationfailed::class, $awaiting, null, ['cancelTwoOrder', 'restoreQuote', 'failOrder'], [self::FAILED]],
        ];
        return $rows;
    }

    #[DataProvider('cases')]
    public function testAReturnLinkActsOnlyOnAnAwaitingOrder(
        string $controllerClass,
        array $stateAndStatus,
        ?callable $cancelFailure,
        array $expectedCalls,
        ?array $expectedShown,
        string $expectedRedirect = 'checkout/cart',
        array $expectedSession = []
    ): void {
        [$state, $status] = $stateAndStatus;
        $order = (new Order())->setState($state)->setStatus($status)
            ->setId(42)->setIncrementId('100000042')->setQuoteId(7);
        $service = $this->orderService($order, $cancelFailure ? $cancelFailure() : null);
        $session = new CheckoutSession();

        $this->controller($controllerClass, $service, $session)->execute();

        $description = $this->dataName();
        $this->assertSame($expectedCalls, $this->calls, "$description: actions");
        if ($expectedShown !== null) {
            $this->assertSame($expectedShown, $this->shown, "$description: buyer message");
        }
        $this->assertSame(['https://shop.test/' . $expectedRedirect], $this->redirects, "$description: redirect");
        $this->assertSame($expectedSession, $session->getData(), "$description: checkout session");
        $this->assertSame([$state, $status], [$order->getState(), $order->getStatus()], "$description: order untouched");
    }

    private function orderService(Order $order, ?\Exception $cancelFailure): OrderService
    {
        $recorded = [
            'getTwoOrderFromApi', 'confirmOrder', 'processOrder', 'cancelTwoOrder',
            'restoreQuote', 'failOrder', 'addOrderComment',
        ];
        $service = $this->getMockBuilder(OrderService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(array_merge(['getOrderByReference'], $recorded))
            ->getMock();
        $service->method('getOrderByReference')->willReturn($order);
        foreach ($recorded as $method) {
            $service->method($method)->willReturnCallback(function () use ($method, $cancelFailure, $service) {
                $this->calls[] = $method;
                if ($method === 'cancelTwoOrder' && $cancelFailure !== null) {
                    throw $cancelFailure;
                }
                return $method === 'cancelTwoOrder' ? true : $service;
            });
        }

        $repository = $this->createMock(OrderRepositoryInterface::class);
        $repository->method('save')->willReturnCallback(function ($saved) {
            $this->calls[] = 'save';
            return $saved;
        });
        $brand = $this->createMock(BrandRegistryInterface::class);
        $brand->method('getProductName')->willReturn('Two');
        $values = [
            'brandRegistry' => $brand,
            'logRepository' => $this->createMock(LogRepository::class),
            'orderRepository' => $repository,
        ];
        foreach ($values as $property => $value) {
            (new \ReflectionProperty(OrderService::class, $property))->setValue($service, $value);
        }

        return $service;
    }

    private function controller(string $class, OrderService $service, CheckoutSession $session): object
    {
        $reflection = new \ReflectionClass($class);
        $controller = $reflection->newInstanceWithoutConstructor();

        $brand = $this->createMock(BrandRegistryInterface::class);
        $brand->method('getProductName')->willReturn('Two');
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

        $values = [
            'orderService' => $service,
            'checkoutSession' => $session,
            'brandRegistry' => $brand,
            'messageManager' => $messages,
            '_url' => $url,
            '_response' => $response,
        ];
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

    public function recordRedirect(string $url): void
    {
        $this->redirects[] = $url;
    }
}
