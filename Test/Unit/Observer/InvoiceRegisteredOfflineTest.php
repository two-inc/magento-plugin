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
    private const COMMENT = 'This invoice was recorded in Magento only and Brand was not notified.'
        . ' The order will be fulfilled with Brand when it is shipped.';

    /**
     * @dataProvider cases
     */
    public function testComment(
        string $method,
        string $captureCase,
        string $trigger,
        bool $ownFulfilment,
        bool $expectComment,
        string $description
    ): void {
        $config = $this->createMock(ConfigRepository::class);
        $config->method('getFulfillTrigger')->willReturn($trigger);
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
        $payment = new Payment();
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

        (new InvoiceRegisteredOffline($config, $brand, $overlay))->execute($observer);

        if (!$expectComment) {
            $this->assertSame([], $order->comments, $description);
            return;
        }
        $this->assertCount(1, $order->comments, $description);
        [$comment, $status, $visibleOnFront, $history] = $order->comments[0];
        $this->assertSame(self::COMMENT, $comment, $description);
        $this->assertFalse($status, $description . ': keeps the order status');
        $this->assertFalse($visibleOnFront, $description . ': hidden from the customer');
        $this->assertFalse($history->getIsCustomerNotified(), $description . ': customer not notified');
    }

    public static function cases(): array
    {
        $offline = Invoice::CAPTURE_OFFLINE;
        $online = Invoice::CAPTURE_ONLINE;

        return [
            ['two_payment', $offline, 'shipment', false, true, 'offline with the shipment trigger gives a comment'],
            ['brand_payment', $offline, 'shipment', false, true, 'same on a brand overlay method'],
            ['two_payment', $online, 'shipment', false, false, 'online capture gives none'],
            ['two_payment', Invoice::NOT_CAPTURE, 'shipment', false, false, 'no capture gives none'],
            ['checkmo', $offline, 'shipment', false, false, 'a non-Two method gives none'],
            ['two_payment', $offline, 'invoice', false, false, 'the invoice trigger gives none'],
            ['two_payment', $offline, 'complete', false, false, 'the complete trigger gives none'],
            ['two_payment', $offline, 'shipment', true, false, 'the plugin\'s own fulfilment invoice gives none'],
        ];
    }
}
