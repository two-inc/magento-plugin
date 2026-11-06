<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Observer;

use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Config as OrderConfig;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\Order\Payment;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\BrandOverlayRegistryInterface;
use Two\Gateway\Api\BrandRegistryInterface;
use Two\Gateway\Api\Config\RepositoryInterface as ConfigRepository;
use Two\Gateway\Observer\InvoiceRegisteredOffline;

/**
 * TWO-26302: an invoice recorded in Magento only gets one merchant-only comment.
 */
class InvoiceRegisteredOfflineTest extends TestCase
{
    private const SHIPPED = 'This invoice was recorded in Magento only and Brand was not notified.'
        . ' The order will be fulfilled with Brand when it is shipped.';
    private const STATUS = 'This invoice was recorded in Magento only and Brand was not notified.'
        . ' The order will be fulfilled with Brand when its status changes to Complete, closed.';
    private const ONE_STATUS = 'This invoice was recorded in Magento only and Brand was not notified.'
        . ' The order will be fulfilled with Brand when its status changes to Complete.';

    /**
     * @dataProvider cases
     */
    public function testComment(
        string $method,
        ?string $captureCase,
        bool $canCapture,
        string $trigger,
        bool $ownFulfilment,
        array $statuses,
        ?string $expected,
        string $description
    ): void {
        $config = $this->createMock(ConfigRepository::class);
        $config->method('getFulfillTrigger')->willReturn($trigger);
        $config->method('getFulfillOrderStatusList')->willReturn($statuses);
        $orderConfig = $this->getMockBuilder(OrderConfig::class)
            ->disableOriginalConstructor()
            ->addMethods(['getStatusLabel'])
            ->getMock();
        // A status with no label falls back to its code.
        $orderConfig->method('getStatusLabel')->willReturnCallback(
            static fn (string $code): ?string => $code === 'complete' ? 'Complete' : null
        );
        $brand = $this->createMock(BrandRegistryInterface::class);
        $brand->method('getProductName')->willReturn('Brand');
        $overlay = $this->createMock(BrandOverlayRegistryInterface::class);
        $overlay->method('isTwoStackMethod')->willReturnCallback(
            static fn (string $code): bool => in_array($code, ['two_payment', 'brand_payment'], true)
        );

        $order = new class extends Order {
            /** @var array<int, array{0: string, 1: mixed, 2: bool, 3: Order}> */
            public $comments = [];

            public function addCommentToStatusHistory($comment, $status = false, $isVisibleOnFront = false)
            {
                $history = new Order();
                $this->comments[] = [$comment, $status, $isVisibleOnFront, $history];
                return $history;
            }
        };
        $payment = new class ($canCapture) extends Payment {
            private $canCapture;

            public function __construct(bool $canCapture)
            {
                $this->canCapture = $canCapture;
            }

            public function canCapture(): bool
            {
                return $this->canCapture;
            }
        };
        $payment->setMethod($method);
        $order->setPayment($payment);

        $invoice = new Invoice();
        $invoice->setOrder($order);
        $invoice->setRequestedCaptureCase($captureCase);
        if ($ownFulfilment) {
            $invoice->setData(InvoiceRegisteredOffline::FULFILLED_WITH_PROVIDER, true);
        }

        $event = $this->getMockBuilder(Event::class)
            ->disableOriginalConstructor()
            ->addMethods(['getInvoice'])
            ->getMock();
        $event->method('getInvoice')->willReturn($invoice);
        $observer = $this->getMockBuilder(Observer::class)
            ->disableOriginalConstructor()
            ->addMethods(['getEvent'])
            ->getMock();
        $observer->method('getEvent')->willReturn($event);

        (new InvoiceRegisteredOffline($config, $brand, $overlay, $orderConfig))->execute($observer);

        if ($expected === null) {
            $this->assertSame([], $order->comments, $description);
            return;
        }
        $this->assertCount(1, $order->comments, $description);
        [$comment, $status, $visibleOnFront, $history] = $order->comments[0];
        $this->assertSame($expected, $comment, $description);
        $this->assertFalse($status, $description . ': keeps the order status');
        $this->assertFalse($visibleOnFront, $description . ': hidden from the customer');
        $this->assertFalse($history->getIsCustomerNotified(), $description . ': customer not notified');
    }

    public static function cases(): array
    {
        $offline = Invoice::CAPTURE_OFFLINE;
        $online = Invoice::CAPTURE_ONLINE;
        $none = Invoice::NOT_CAPTURE;
        $st = ['complete', 'closed'];
        $shipped = self::SHIPPED;

        return [
            ['two_payment', $offline, false, 'shipment', false, $st, $shipped, 'offline with the shipment trigger gives a comment'],
            ['brand_payment', $offline, false, 'shipment', false, $st, $shipped, 'same on a brand overlay method'],
            ['two_payment', null, false, 'shipment', false, $st, $shipped, 'a REST invoice with no capture case gives a comment'],
            ['two_payment', null, true, 'shipment', false, $st, null, 'no capture case where the method captures online gives none'],
            ['two_payment', $offline, false, 'complete', false, $st, self::STATUS, 'the complete trigger names the statuses'],
            ['two_payment', $offline, false, 'complete', false, ['', 'complete'], self::ONE_STATUS, 'an empty status code is skipped'],
            ['two_payment', $offline, false, 'complete', false, [''], null, 'the complete trigger with no status gives none'],
            ['two_payment', $online, true, 'shipment', false, $st, null, 'online capture gives none'],
            ['two_payment', $none, false, 'shipment', false, $st, null, 'no capture gives none'],
            ['checkmo', $offline, false, 'shipment', false, $st, null, 'a non-Two method gives none'],
            ['two_payment', $offline, true, 'invoice', false, $st, null, 'the invoice trigger gives none'],
            ['two_payment', $offline, false, 'shipment', true, $st, null, 'the plugin\'s own fulfilment invoice gives none'],
        ];
    }
}
