<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Observer;

use Magento\Framework\DataObject;
use Magento\Framework\Event\Observer;
use Magento\Sales\Model\Order;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\BrandOverlayRegistryInterface;
use Two\Gateway\Api\BrandRegistryInterface;
use Two\Gateway\Observer\SalesOrderCancelAfter;
use Two\Gateway\Service\Payment\OrderService;

/**
 * TWO-26300: an order cancelled here because Two already cancelled it sends no
 * cancel back to Two; any other Magento cancel still does.
 */
class SalesOrderCancelAfterSkipSyncTest extends TestCase
{
    public static function cases(): array
    {
        return [
            'a merchant cancel is sent to Two' => [false, 1],
            'a cancel matching Two is not sent back' => [true, 0],
        ];
    }

    #[DataProvider('cases')]
    public function testTheCancelIsSentUnlessTwoAlreadyCancelled(bool $flag, int $expectedSends): void
    {
        $order = (new Order())->setTwoOrderId('two-1')->setPayment((new Order\Payment())->setMethod('two_payment'));
        if ($flag) {
            $order->setData(OrderService::SKIP_CANCEL_SYNC, true);
        }
        $service = $this->getMockBuilder(OrderService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['cancelTwoOrder'])
            ->getMock();
        $service->expects($this->exactly($expectedSends))->method('cancelTwoOrder')->willReturn(true);
        $overlay = $this->createMock(BrandOverlayRegistryInterface::class);
        $overlay->method('isTwoStackMethod')->willReturn(true);
        $brand = $this->createMock(BrandRegistryInterface::class);
        $brand->method('getProductName')->willReturn('Two');
        $lifecycle = new class {
            public function dispatchCancelled($order, array $twoData = [])
            {
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

        $event = new DataObject(['order' => $order]);
        $observer->execute(new class ($event) extends Observer {
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
    }
}
