<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Observer;

use Magento\Framework\DataObject;
use Magento\Framework\DB\TransactionFactory;
use Magento\Framework\Event\Observer;
use Magento\Sales\Api\OrderStatusHistoryRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\Order\Payment;
use Magento\Sales\Model\Order\Status\HistoryFactory;
use Magento\Sales\Model\Service\InvoiceService;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\BrandOverlayRegistryInterface;
use Two\Gateway\Api\BrandRegistryInterface;
use Two\Gateway\Api\Config\RepositoryInterface as ConfigRepository;
use Two\Gateway\Api\Log\RepositoryInterface as LogRepository;
use Two\Gateway\Observer\InvoiceRegisteredOffline;
use Two\Gateway\Observer\SalesOrderSaveAfter;
use Two\Gateway\Observer\SalesOrderShipmentAfter;
use Two\Gateway\Service\Api\Adapter;
use Two\Gateway\Service\Invoice\UploadService;
use Two\Gateway\Service\Order\ComposeShipment;
use Two\Gateway\Service\Order\LifecycleEventDispatcher;
use Two\Gateway\Service\Order\OrderPostprocessor;

/**
 * TWO-26302: the fulfil-on status tells Two exactly once, whether or not the
 * merchant has already invoiced in Magento, and the plugin's own fulfilment
 * invoices are flagged so they get no "Two was not notified" comment.
 */
class FulfilmentInvoiceTest extends TestCase
{
    /** @var int */
    private $fulfils = 0;

    /** @var Invoice[] */
    private $registered = [];

    /**
     * Each case saves the order in its fulfil-on status twice.
     *
     * @dataProvider statusCases
     */
    public function testStatusChangeFulfilsOnce(
        array $additionalInformation,
        bool $hasInvoices,
        float $leftToInvoice,
        int $expectedFulfils,
        int $expectedInvoices,
        string $description
    ): void {
        $config = $this->createMock(ConfigRepository::class);
        $config->method('getFulfillTrigger')->willReturn('complete');
        $config->method('getFulfillOrderStatusList')->willReturn(['complete']);
        $order = $this->order($additionalInformation, $hasInvoices);

        $observer = new SalesOrderSaveAfter(
            $config,
            $this->createMock(BrandRegistryInterface::class),
            $this->adapter(),
            $this->historyFactory(),
            $this->createMock(OrderStatusHistoryRepositoryInterface::class),
            $this->invoiceService($leftToInvoice),
            $this->transactionFactory(),
            $this->overlay(),
            $this->passThrough()
        );
        $event = new FulfilmentObserver(new DataObject(['order' => $order]));
        $observer->execute($event);
        $observer->execute($event);

        $this->assertSame($expectedFulfils, $this->fulfils, $description . ': fulfilments sent');
        $this->assertCount($expectedInvoices, $this->registered, $description . ': invoices created');
        foreach ($this->registered as $invoice) {
            $this->assertTrue(
                (bool)$invoice->getData(InvoiceRegisteredOffline::FULFILLED_WITH_PROVIDER),
                $description . ': invoice flagged'
            );
        }
    }

    public static function statusCases(): array
    {
        $fulfilled = ['marked_completed' => true];

        return [
            [[], false, 100.0, 1, 1, 'no invoice gives one fulfil and the plugin invoice'],
            [[], true, 0.0, 1, 0, 'a manual offline invoice for everything still gives one fulfil'],
            [[], true, 40.0, 1, 1, 'a partial manual invoice gives one fulfil and invoices the rest'],
            [$fulfilled, true, 0.0, 0, 0, 'after the plugin\'s own fulfilment there is no second fulfil'],
        ];
    }

    public function testShipmentFulfilmentFlagsItsInvoice(): void
    {
        $config = $this->createMock(ConfigRepository::class);
        $config->method('getFulfillTrigger')->willReturn('shipment');
        $order = $this->order([], false);

        (new SalesOrderShipmentAfter(
            $config,
            $this->createMock(BrandRegistryInterface::class),
            $this->adapter(),
            $this->historyFactory(),
            $this->createMock(OrderStatusHistoryRepositoryInterface::class),
            $this->createMock(ComposeShipment::class),
            $this->invoiceService(100.0),
            $this->transactionFactory(),
            $this->overlay(),
            $this->createMock(UploadService::class),
            $this->createMock(LogRepository::class),
            $this->createMock(LifecycleEventDispatcher::class),
            $this->passThrough()
        ))->execute(new FulfilmentObserver(new DataObject(['shipment' => new DataObject(['order' => $order])])));

        $this->assertSame(1, $this->fulfils);
        $this->assertCount(1, $this->registered);
        $this->assertTrue((bool)$this->registered[0]->getData(InvoiceRegisteredOffline::FULFILLED_WITH_PROVIDER));
    }

    private function order(array $additionalInformation, bool $hasInvoices): Order
    {
        $order = new class extends Order implements \Magento\Sales\Api\Data\OrderInterface {
            public function getAllVisibleItems(): array
            {
                return [];
            }
        };
        $order->setData('entity_id', 7);
        $order->setData('store_id', 1);
        $order->setData('two_order_id', 'remote-order-id');
        $order->setData('status', 'complete');
        if ($hasInvoices) {
            $order->setData('invoices', true);
        }
        $payment = new Payment();
        $payment->setData('method', 'two_payment');
        $payment->setData('method_instance', new FulfilmentMethodInstance());
        $payment->setData('additional_information', $additionalInformation);
        $order->setData('payment', $payment);

        return $order;
    }

    private function adapter(): Adapter
    {
        $adapter = $this->createMock(Adapter::class);
        $adapter->method('execute')->willReturnCallback(function (): array {
            $this->fulfils++;
            return ['fulfilled_order' => ['id' => 'fulfilled-id']];
        });

        return $adapter;
    }

    private function invoiceService(float $leftToInvoice): InvoiceService
    {
        $service = $this->getMockBuilder(InvoiceService::class)
            ->disableOriginalConstructor()
            ->addMethods(['prepareInvoice'])
            ->getMock();
        $service->method('prepareInvoice')->willReturnCallback(function () use ($leftToInvoice): Invoice {
            $registered = &$this->registered;
            $invoice = new class ($registered) extends Invoice {
                /** @var array */
                private $registered;

                public function __construct(array &$registered)
                {
                    $this->registered = &$registered;
                }

                public function register(): self
                {
                    $this->registered[] = $this;
                    return $this;
                }

                public function pay(): self
                {
                    return $this;
                }
            };
            $invoice->setData('grand_total', $leftToInvoice);

            return $invoice;
        });

        return $service;
    }

    private function historyFactory(): HistoryFactory
    {
        $factory = $this->getMockBuilder(HistoryFactory::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['create'])
            ->getMock();
        $factory->method('create')->willReturnCallback(static fn () => new \Magento\Sales\Model\Order\Status\History());

        return $factory;
    }

    private function transactionFactory(): TransactionFactory
    {
        $transaction = new class {
            public function addObject($object): self
            {
                return $this;
            }

            public function save(): self
            {
                return $this;
            }
        };
        $factory = $this->getMockBuilder(TransactionFactory::class)
            ->disableOriginalConstructor()
            ->addMethods(['create'])
            ->getMock();
        $factory->method('create')->willReturn($transaction);

        return $factory;
    }

    private function overlay(): BrandOverlayRegistryInterface
    {
        $overlay = $this->createMock(BrandOverlayRegistryInterface::class);
        $overlay->method('isTwoStackMethod')->willReturn(true);

        return $overlay;
    }

    private function passThrough(): OrderPostprocessor
    {
        $postprocessor = $this->createMock(OrderPostprocessor::class);
        $postprocessor->method('process')->willReturnArgument(1);

        return $postprocessor;
    }
}

class FulfilmentObserver extends Observer
{
    /** @var DataObject */
    private $event;

    public function __construct(DataObject $event)
    {
        $this->event = $event;
    }

    public function getEvent(): DataObject
    {
        return $this->event;
    }
}

class FulfilmentMethodInstance
{
    /**
     * @param mixed $response
     * @return null
     */
    public function getErrorFromResponse($response)
    {
        return null;
    }
}
