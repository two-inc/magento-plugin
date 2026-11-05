<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Model;

use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Payment\Model\InfoInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\Order\Payment;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\BrandRegistryInterface;
use Two\Gateway\Api\Config\RepositoryInterface as ConfigRepository;
use Two\Gateway\Api\Log\RepositoryInterface as LogRepository;
use Two\Gateway\Model\Two;
use Two\Gateway\Observer\PaymentCaptureInvoice;
use Two\Gateway\Service\Api\Adapter;
use Two\Gateway\Service\Order\ComposeCapture;
use Two\Gateway\Service\Order\OrderPostprocessor;
use Two\Gateway\Test\Stubs\AbstractSalesModelStub;

/**
 * TWO-26278: core captures online from inside Invoice::register(), before the
 * invoice is saved, so the order's invoice collection holds only the earlier
 * invoices. A partial capture must compose from the invoice being registered,
 * which core names on sales_order_payment_capture, and key it as the next
 * unsaved invoice.
 */
class TwoCaptureRegisteringInvoiceTest extends TestCase
{
    public static function savedInvoices(): array
    {
        // [invoices saved before this one, expected Idempotency-Key, description]
        return [
            [0, '/^fulfil_remote-order-id_invoice-n1-[a-z0-9]+$/', 'first partial invoice: none saved yet'],
            [1, '/^fulfil_remote-order-id_invoice-n2-[a-z0-9]+$/', 'second partial invoice: the first is saved'],
        ];
    }

    /**
     * @dataProvider savedInvoices
     */
    public function testAPartialCaptureComposesTheInvoiceBeingRegistered(
        int $saved,
        string $keyPattern,
        string $description
    ): void {
        $order = $this->order($saved);
        $registering = (new Invoice())->setOrder($order);
        $payment = (new CapturePayment())->setOrder($order);
        $this->dispatchCapture($payment, $registering);

        $compose = $this->createMock(ComposeCapture::class);
        $compose->expects($this->once())->method('execute')
            ->with($this->identicalTo($registering))
            ->willReturn(['gross_amount' => '10.00']);
        $sent = [];

        try {
            $this->two($compose, $sent)->capture($payment, 10.0);
            $this->fail("$description: the capture was not sent");
        } catch (CaptureSent $e) {
            // Stopped at the send.
        }

        $this->assertSame($registering, $sent['context']['invoice'], "$description: hook context invoice");
        $this->assertSame(['partial' => ['gross_amount' => '10.00']], $sent['payload'], "$description: payload");
        $this->assertMatchesRegularExpression($keyPattern, (string)$sent['key'], "$description: Idempotency-Key");
    }

    private function dispatchCapture(Payment $payment, Invoice $invoice): void
    {
        $event = $this->getMockBuilder(Event::class)
            ->disableOriginalConstructor()
            ->addMethods(['getPayment', 'getInvoice'])
            ->getMock();
        $event->method('getPayment')->willReturn($payment);
        $event->method('getInvoice')->willReturn($invoice);
        $observer = $this->getMockBuilder(Observer::class)
            ->disableOriginalConstructor()
            ->addMethods(['getEvent'])
            ->getMock();
        $observer->method('getEvent')->willReturn($event);

        (new PaymentCaptureInvoice())->execute($observer);
    }

    /**
     * An order of two units with one invoiced (the invoice being registered
     * has already added its quantity, as Invoice\Item::register() does), and
     * $saved invoices in its collection.
     */
    private function order(int $saved): Order
    {
        $item = new class extends AbstractSalesModelStub {
        };
        $item->setData('qty_ordered', 2);
        $item->setData('qty_invoiced', 1);

        $invoices = [];
        for ($i = 1; $i <= $saved; $i++) {
            $invoices[] = (new Invoice())->setData('id', (string)(100 + $i));
        }

        $order = new CaptureOrder();
        $order->setData('store_id', 1);
        $order->setData('two_order_id', 'remote-order-id');
        $order->setData('all_visible_items', [$item]);
        $order->setData('invoice_collection', $invoices);

        return $order;
    }

    private function two(ComposeCapture $compose, array &$sent): Two
    {
        $config = $this->createMock(ConfigRepository::class);
        $config->method('getFulfillTrigger')->willReturn('invoice');

        $adapter = $this->createMock(Adapter::class);
        $adapter->method('withIdempotencyKey')->willReturnCallback(
            function (?string $key) use (&$sent, &$adapter): Adapter {
                $sent['key'] = $key;
                return $adapter;
            }
        );

        $postprocessor = $this->createMock(OrderPostprocessor::class);
        $postprocessor->method('process')->willReturnCallback(
            function (string $type, array $payload, array $context) use (&$sent) {
                $sent['payload'] = $payload;
                $sent['context'] = $context;
                throw new CaptureSent();
            }
        );

        $two = $this->getMockBuilder(Two::class)->disableOriginalConstructor()->onlyMethods([])->getMock();
        foreach ([
            'configRepository' => $config,
            'brandRegistry' => $this->createMock(BrandRegistryInterface::class),
            'apiAdapter' => $adapter,
            'logRepository' => $this->createMock(LogRepository::class),
            'composeCapture' => $compose,
            'orderPostprocessor' => $postprocessor,
        ] as $property => $value) {
            (new \ReflectionProperty(Two::class, $property))->setValue($two, $value);
        }

        return $two;
    }
}

/**
 * Core's order is an OrderInterface; the suite's Order stub is not.
 */
class CaptureOrder extends Order implements \Magento\Sales\Api\Data\OrderInterface
{
}

/**
 * Core's order payment is an InfoInterface; the suite's Payment stub is not.
 */
class CapturePayment extends Payment implements InfoInterface
{
}

/**
 * An Error rather than an Exception, so capture()'s own handling cannot swallow it.
 */
class CaptureSent extends \Error
{
}
