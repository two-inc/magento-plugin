<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Service\Order;

use Magento\Sales\Api\OrderStatusHistoryRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use Magento\Sales\Model\Order\Status\History;
use Magento\Sales\Model\Order\Status\HistoryFactory;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\BrandRegistryInterface;
use Two\Gateway\Model\Two;
use Two\Gateway\Observer\SalesOrderShipmentAfter;
use Two\Gateway\Service\Order\LifecycleEventDispatcher;

/**
 * TWO-26302: a fulfilment marks the order completed only when Two returned the
 * fulfilled order with its id. Covers the capture and shipment paths; the
 * status-change path runs the same cases in StatusFulfilmentAfterCommitTest.
 */
class FulfilledOrderMarkerTest extends TestCase
{
    /** @var string[] order comments written */
    private $comments = [];

    /** @var int completion events dispatched */
    private $completedEvents = 0;

    /**
     * Two's fulfilment responses with no error, and the completion comment each
     * should add (null: no marker, no comment).
     */
    public static function responses(): array
    {
        return [
            [['fulfilled_order' => ['id' => 'fulfilled-id']], 'Two order marked as completed.', 'a full fulfilment'],
            [
                ['fulfilled_order' => ['id' => 'fulfilled-id'], 'remained_order' => ['id' => 'remained-id']],
                'Two order marked as partially completed.',
                'a partial fulfilment',
            ],
            [[], null, 'no fulfilled order'],
            [['fulfilled_order' => null], null, 'a null fulfilled order'],
            [['fulfilled_order' => []], null, 'an empty fulfilled order'],
            [['fulfilled_order' => ['remained' => 'x']], null, 'a fulfilled order without its id'],
        ];
    }

    public static function sitesAndResponses(): array
    {
        $cases = [];
        foreach ([Two::class, SalesOrderShipmentAfter::class] as $site) {
            foreach (self::responses() as [$response, $expectedComment, $description]) {
                $cases[] = [$site, $response, $expectedComment, $site . ': ' . $description];
            }
        }

        return $cases;
    }

    /**
     * @dataProvider sitesAndResponses
     */
    public function testTheMarkerNeedsTheFulfilledOrderId(
        string $site,
        array $response,
        ?string $expectedComment,
        string $description
    ): void {
        $order = new Order();
        $payment = new Payment();
        $payment->setData('additional_information', ['existing' => 'kept']);
        $order->setData('payment', $payment);

        $parse = new \ReflectionMethod($site, 'parseFulfillResponse');
        $parse->setAccessible(true);
        $parse->invoke($this->instance($site), $response, $order);

        $marked = $expectedComment !== null;
        $this->assertSame(
            $marked ? ['existing' => 'kept', 'marked_completed' => true] : ['existing' => 'kept'],
            $payment->getData('additional_information'),
            $description . ': marker'
        );
        $this->assertSame($marked ? [$expectedComment] : [], $this->comments, $description . ': comment');
        $this->assertSame($marked ? 1 : 0, $this->completedEvents, $description . ': completion event');
    }

    private function instance(string $site): object
    {
        $brand = $this->createMock(BrandRegistryInterface::class);
        $brand->method('getProductName')->willReturn('Two');
        $historyFactory = $this->getMockBuilder(HistoryFactory::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['create'])
            ->getMock();
        $historyFactory->method('create')->willReturnCallback(static fn () => new History());
        $historyRepository = $this->createMock(OrderStatusHistoryRepositoryInterface::class);
        $historyRepository->method('save')->willReturnCallback(function ($history) {
            $this->comments[] = (string)$history->getComment();
            return $history;
        });
        $lifecycle = $this->createMock(LifecycleEventDispatcher::class);
        $lifecycle->method('dispatchCompleted')->willReturnCallback(function (): void {
            $this->completedEvents++;
        });

        $instance = (new \ReflectionClass($site))->newInstanceWithoutConstructor();
        foreach ([
            'brandRegistry' => $brand,
            'historyFactory' => $historyFactory,
            'orderStatusHistoryRepository' => $historyRepository,
            'lifecycleEvents' => $lifecycle,
        ] as $name => $value) {
            $property = new \ReflectionProperty($site, $name);
            $property->setAccessible(true);
            $property->setValue($instance, $value);
        }

        return $instance;
    }
}
