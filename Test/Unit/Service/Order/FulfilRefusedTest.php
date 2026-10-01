<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Service\Order;

use Magento\Framework\DataObject;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Model\Order;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\BrandOverlayRegistryInterface;
use Two\Gateway\Api\BrandRegistryInterface;
use Two\Gateway\Api\Config\RepositoryInterface as ConfigRepository;
use Two\Gateway\Api\Log\RepositoryInterface as LogRepository;
use Two\Gateway\Model\Two;
use Two\Gateway\Observer\SalesOrderShipmentAfter;
use Two\Gateway\Service\Api\Adapter;
use Two\Gateway\Service\Order\ComposeCapture;
use Two\Gateway\Service\Order\ComposeShipment;
use Two\Gateway\Service\Order\OrderPostprocessor;

require_once __DIR__ . '/OrderPostprocessingSendSitesTest.php';

/**
 * TWO-26150: a fulfilment refused with a bare non-2xx status carries no
 * error_code. Both fulfil paths must refuse with the status, not trip over
 * the missing key: a warning there is an exception in developer mode, with
 * the wrong message.
 */
class FulfilRefusedTest extends TestCase
{
    protected function setUp(): void
    {
        set_error_handler(
            static function (int $severity, string $message, string $file, int $line): bool {
                throw new \ErrorException($message, 0, $severity, $file, $line);
            },
            E_WARNING | E_NOTICE
        );
    }

    protected function tearDown(): void
    {
        restore_error_handler();
    }

    /**
     * @return array<int, array{string, string}> [fulfil path, description]
     */
    public static function fulfilPaths(): array
    {
        return [
            ['captureOnInvoice', 'capture on an invoice'],
            ['captureOnShipment', 'capture on a shipment'],
        ];
    }

    #[DataProvider('fulfilPaths')]
    public function testBareNon2xxIsRefusedWithItsStatus(string $path, string $description): void
    {
        try {
            $this->$path();
            $this->fail("$description: no refusal");
        } catch (LocalizedException $e) {
            $this->assertStringContainsString('HTTP status 405', $e->getMessage(), $description);
        }
    }

    private function captureOnInvoice(): void
    {
        $config = $this->createMock(ConfigRepository::class);
        $config->method('getFulfillTrigger')->willReturn('invoice');
        $this->two(['configRepository' => $config])->capture(new SendSitePayment($this->order()), 0.0);
    }

    private function captureOnShipment(): void
    {
        $config = $this->createMock(ConfigRepository::class);
        $config->method('getFulfillTrigger')->willReturn('shipment');
        $overlay = $this->createMock(BrandOverlayRegistryInterface::class);
        $overlay->method('isTwoStackMethod')->willReturn(true);

        (new SalesOrderShipmentAfter(
            $config,
            $this->brand(),
            $this->adapter(),
            $this->createMock(\Magento\Sales\Model\Order\Status\HistoryFactory::class),
            $this->createMock(\Magento\Sales\Api\OrderStatusHistoryRepositoryInterface::class),
            $this->createMock(ComposeShipment::class),
            $this->createMock(\Magento\Sales\Model\Service\InvoiceService::class),
            $this->createMock(\Magento\Framework\DB\TransactionFactory::class),
            $overlay,
            $this->createMock(\Two\Gateway\Service\Invoice\UploadService::class),
            $this->createMock(LogRepository::class),
            $this->createMock(\Two\Gateway\Service\Order\LifecycleEventDispatcher::class),
            $this->passThrough()
        ))->execute(new SendSiteObserver(new DataObject(['shipment' => new DataObject(['order' => $this->order()])])));
    }

    private function two(array $collaborators = []): Two
    {
        $two = $this->getMockBuilder(Two::class)->disableOriginalConstructor()->onlyMethods([])->getMock();
        $collaborators += [
            'configRepository' => $this->createMock(ConfigRepository::class),
            'brandRegistry' => $this->brand(),
            'apiAdapter' => $this->adapter(),
            'logRepository' => $this->createMock(LogRepository::class),
            'composeCapture' => $this->createMock(ComposeCapture::class),
            'orderPostprocessor' => $this->passThrough(),
        ];
        foreach ($collaborators as $property => $value) {
            (new \ReflectionProperty(Two::class, $property))->setValue($two, $value);
        }

        return $two;
    }

    private function order(): Order
    {
        $order = new SendSiteOrder();
        $order->setData('store_id', 1);
        $order->setData('entity_id', 7);
        $order->setData('two_order_id', 'remote-order-id');
        $order->setData('status', 'complete');
        $order->setData('all_visible_items', []);
        $payment = new \Magento\Sales\Model\Order\Payment();
        $payment->setData('method', 'two_payment');
        $payment->setData('method_instance', $this->two());
        $order->setData('payment', $payment);

        return $order;
    }

    /** The adapter's shape for a 405 whose body is not JSON. */
    private function adapter(): Adapter
    {
        $adapter = $this->createMock(Adapter::class);
        $adapter->method('execute')->willReturn(['http_status' => 405]);

        return $adapter;
    }

    private function passThrough(): OrderPostprocessor
    {
        $postprocessor = $this->createMock(OrderPostprocessor::class);
        $postprocessor->method('process')->willReturnArgument(1);

        return $postprocessor;
    }

    private function brand(): BrandRegistryInterface
    {
        $brand = $this->createMock(BrandRegistryInterface::class);
        $brand->method('getProductName')->willReturn('Two');

        return $brand;
    }
}
