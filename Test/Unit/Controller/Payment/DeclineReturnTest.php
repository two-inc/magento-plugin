<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Controller\Payment;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Message\ManagerInterface;
use Magento\Sales\Model\Order;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\BrandRegistryInterface;
use Two\Gateway\Controller\Payment\Cancel;
use Two\Gateway\Controller\Payment\Verificationfailed;
use Two\Gateway\Model\Two;
use Two\Gateway\Service\Payment\OrderService;
use Two\Gateway\Service\Payment\PendingOrderResolution;
use Two\Gateway\Service\Payment\PendingOrderResolver as R;

/**
 * TWO-27389: a buyer Two did not approve, or who left verification, comes
 * back through the cancel or failed-verification link wanting to pay for the
 * same basket another way. What Two reports decides what happens to the
 * order; the basket comes back only in the browser that placed the order.
 */
class DeclineReturnTest extends TestCase
{
    private const DECLINED = 'Two could not approve this purchase. Please choose another payment method.';
    private const STILL_CHECKING = 'Your order is still being checked with Two. The shop will confirm your order by email if it goes through.';
    private const PAYMENT_STEP = 'checkout#payment';
    private const CART = 'checkout/cart';
    private const SUCCESS = 'checkout/onepage/success';

    private const AWAITING = [Order::STATE_PENDING_PAYMENT, Two::STATUS_PENDING];
    private const CANCELLED = [Order::STATE_CANCELED, 'canceled'];
    private const FAILED = [Order::STATE_CANCELED, Two::STATUS_FAILED];
    private const PROCESSING = [Order::STATE_PROCESSING, Order::STATE_PROCESSING];

    /** The order's own quote; another number is another basket. */
    private const QUOTE = 7;

    /** @var string[] */
    private $calls = [];

    /** @var string[] */
    private $errors = [];

    /** @var string[] */
    private $notices = [];

    /** @var string[] */
    private $redirects = [];

    public static function cases(): array
    {
        $restore = ['resolve', 'restoreQuote'];
        // order before, Two's answer (outcome, order after, buyer message), basket restored now,
        // the session's quote, actions, errors, notices, landing
        $table = [
            'Two declined, same browser: basket back on the payment step' =>
                [self::AWAITING, [R::OUTCOME_DECLINED, self::CANCELLED], true, null, $restore, [self::DECLINED], [], self::PAYMENT_STEP],
            'Two cancelled, same browser: basket back on the payment step' =>
                [self::AWAITING, [R::OUTCOME_CANCELLED, self::CANCELLED], true, null, $restore, [self::DECLINED], [], self::PAYMENT_STEP],
            'buyer left verification, same browser: basket back on the payment step' =>
                [self::AWAITING, [R::OUTCOME_UNVERIFIED, self::CANCELLED], true, null, $restore, [self::DECLINED], [], self::PAYMENT_STEP],
            'Two declined, another browser: the message, nothing restored' =>
                [self::AWAITING, [R::OUTCOME_DECLINED, self::CANCELLED], false, null, $restore, [self::DECLINED], [], self::CART],
            'Two declined, another browser with its own basket: that basket is left alone' =>
                [self::AWAITING, [R::OUTCOME_DECLINED, self::CANCELLED], false, 99, $restore, [self::DECLINED], [], self::CART],
            'Two verified: processed, success page' =>
                [self::AWAITING, [R::OUTCOME_CONFIRMED, self::PROCESSING], false, null, ['resolve'], [], [], self::SUCCESS],
            'Two had confirmed: processed, success page' =>
                [self::AWAITING, [R::OUTCOME_PROCESSED, self::PROCESSING], false, null, ['resolve'], [], [], self::SUCCESS],
            'settled first by another check: success page' =>
                [self::AWAITING, [R::OUTCOME_ALREADY_PROCESSED, self::PROCESSING], false, null, ['resolve'], [], [], self::SUCCESS],
            'cancelled meanwhile by another request: basket back on the payment step' =>
                [self::AWAITING, [R::OUTCOME_NOT_WAITING, self::CANCELLED], true, null, $restore, [self::DECLINED], [], self::PAYMENT_STEP],
            'Two refused the cancel: order and basket kept, its message shown' =>
                [self::AWAITING, [R::OUTCOME_FAILED, self::AWAITING, 'Cannot cancel.'], false, null, ['resolve'], ['Cannot cancel.'], [], self::CART],
            'Two could not be asked: nothing changed' =>
                [self::AWAITING, [R::OUTCOME_FAILED, self::AWAITING], false, null, ['resolve'], [], [self::STILL_CHECKING], self::CART],
            'Two reports a state not acted on: nothing changed' =>
                [self::AWAITING, [R::OUTCOME_UNCHANGED, self::AWAITING], false, null, ['resolve'], [], [self::STILL_CHECKING], self::CART],
            'another request holds the order: nothing changed' =>
                [self::AWAITING, [R::OUTCOME_BUSY, self::AWAITING], false, null, ['resolve'], [], [self::STILL_CHECKING], self::CART],
            'the scheduled check already closed it for a decline: basket back on the payment step' =>
                [self::CANCELLED, null, true, null, ['restoreQuote'], [self::DECLINED], [], self::PAYMENT_STEP],
            'an order failed before this change: basket back on the payment step' =>
                [self::FAILED, null, true, null, ['restoreQuote'], [self::DECLINED], [], self::PAYMENT_STEP],
            'a second click, basket already back: the same page, nothing changed' =>
                [self::CANCELLED, null, false, self::QUOTE, ['restoreQuote'], [self::DECLINED], [], self::PAYMENT_STEP],
            'a stale link in another browser: nothing changed' =>
                [self::CANCELLED, null, false, 99, ['restoreQuote'], [self::DECLINED], [], self::CART],
            'an order already processed: its success page, nothing changed' =>
                [self::PROCESSING, null, false, null, [], [], [], self::SUCCESS],
            'an order held by the merchant: nothing changed' =>
                [[Order::STATE_PENDING_PAYMENT, 'holded'], null, false, null, [], [], [], self::CART],
        ];
        $rows = [];
        foreach ([Cancel::class, Verificationfailed::class] as $controller) {
            $name = substr($controller, strrpos($controller, '\\') + 1);
            foreach ($table as $description => $row) {
                $rows["$name: $description"] = array_merge([$controller], $row);
            }
        }
        return $rows;
    }

    #[DataProvider('cases')]
    public function testTheReturnFollowsWhatTwoReports(
        string $controllerClass,
        array $before,
        ?array $twoAnswer,
        bool $restored,
        ?int $sessionQuoteId,
        array $expectedCalls,
        array $expectedErrors,
        array $expectedNotices,
        string $expectedLanding
    ): void {
        $order = $this->order($before);
        $session = new CheckoutSession();
        if ($sessionQuoteId !== null) {
            $session->setQuoteId($sessionQuoteId);
        }
        $resolution = null;
        if ($twoAnswer !== null) {
            [$outcome, $after, $buyerMessage] = $twoAnswer + [2 => ''];
            $resolution = new PendingOrderResolution($outcome, $this->order($after), '', '', $buyerMessage);
        }

        $this->controller($controllerClass, $order, $resolution, $restored, $session)->execute();

        $description = $this->dataName();
        $this->assertSame($expectedCalls, $this->calls, "$description: actions");
        $this->assertSame($expectedErrors, $this->errors, "$description: error shown");
        $this->assertSame($expectedNotices, $this->notices, "$description: notice shown");
        $this->assertSame(['https://shop.test/' . $expectedLanding], $this->redirects, "$description: landing");
        if ($expectedLanding === self::SUCCESS) {
            $this->assertSame('100000042', $session->getLastRealOrderId(), "$description: success page for this order");
        }
    }

    private function order(array $stateAndStatus): Order
    {
        [$state, $status] = $stateAndStatus;
        return (new Order())->setState($state)->setStatus($status)
            ->setId(42)->setIncrementId('100000042')->setQuoteId(self::QUOTE);
    }

    private function controller(
        string $class,
        Order $order,
        ?PendingOrderResolution $resolution,
        bool $restored,
        CheckoutSession $session
    ): object {
        $service = $this->getMockBuilder(OrderService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getOrderByReference', 'restoreQuote', 'reloadOrder'])
            ->getMock();
        $service->method('getOrderByReference')->willReturn($order);
        $service->method('reloadOrder')->willReturnArgument(0);
        $service->method('restoreQuote')->willReturnCallback(function (Order $restoring) use ($restored) {
            $this->calls[] = 'restoreQuote';
            $this->assertSame(self::QUOTE, $restoring->getQuoteId(), 'restores the order\'s own quote');
            return $restored;
        });

        $resolver = $this->createMock(R::class);
        $resolver->method('resolve')->willReturnCallback(function ($resolving, $trigger) use ($resolution) {
            $this->calls[] = 'resolve';
            $this->assertSame(R::TRIGGER_BUYER_DECLINE, $trigger, 'resolved as a decline return');
            return $resolution;
        });

        $brand = $this->createMock(BrandRegistryInterface::class);
        $brand->method('getProductName')->willReturn('Two');
        $messages = $this->createMock(ManagerInterface::class);
        $messages->method('addErrorMessage')->willReturnCallback(function ($message) use ($messages) {
            $this->errors[] = (string)$message;
            return $messages;
        });
        $messages->method('addNoticeMessage')->willReturnCallback(function ($message) use ($messages) {
            $this->notices[] = (string)$message;
            return $messages;
        });
        $url = new class {
            public function getUrl($path = null, $params = null)
            {
                $fragment = isset($params['_fragment']) ? '#' . $params['_fragment'] : '';
                return 'https://shop.test/' . $path . $fragment;
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

        $reflection = new \ReflectionClass($class);
        $controller = $reflection->newInstanceWithoutConstructor();
        $values = [
            'orderService' => $service,
            'pendingOrderResolver' => $resolver,
            'brandRegistry' => $brand,
            'checkoutSession' => $session,
            'messageManager' => $messages,
            '_url' => $url,
            '_response' => $response,
        ];
        foreach ($values as $property => $value) {
            $owner = $reflection;
            while (!$owner->hasProperty($property) && $owner->getParentClass()) {
                $owner = $owner->getParentClass();
            }
            $this->assertTrue($owner->hasProperty($property), "$class has $property");
            $owner->getProperty($property)->setValue($controller, $value);
        }
        return $controller;
    }

    public function recordRedirect(string $url): void
    {
        $this->redirects[] = $url;
    }
}
