<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Observer;

use Magento\Framework\Message\ManagerInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\BrandOverlayRegistryInterface;
use Two\Gateway\Api\BrandRegistryInterface;
use Two\Gateway\Api\Config\RepositoryInterface as ConfigRepository;
use Two\Gateway\Model\Two;
use Two\Gateway\Observer\SalesOrderAddressUpdate;
use Two\Gateway\Service\Api\Adapter;
use Two\Gateway\Service\Order\ComposeOrder;
use Two\Gateway\Service\Order\OrderPostprocessor;

require_once __DIR__ . '/SalesOrderAddressUpdateOptionalFieldsTest.php';

/**
 * TWO-26150: an order edit the API refused with a bare non-2xx status was
 * recorded in the order history as accepted. Runs the real
 * Two::getErrorFromResponse() so the history line follows its verdict.
 */
class SalesOrderAddressUpdateFailedEditTest extends TestCase
{
    /**
     * @return array<int, array{int, string}> [status the adapter returned, description]
     */
    public static function refusedEditCases(): array
    {
        return [
            [405, 'edit refused with 405 and an HTML body'],
            [500, 'edit failed with 500 and an HTML body'],
            [502, 'edit failed at a gateway with 502 and an HTML body'],
        ];
    }

    #[DataProvider('refusedEditCases')]
    public function testRefusedEditIsRecordedAsAFailure(int $status, string $description): void
    {
        $order = new AddressUpdateOrderStub();
        $order->setData('store_id', 1);
        $order->setData('two_order_id', 'remote-order-id');
        $order->setData('two_order_reference', 'order-reference');
        $order->setData('payment', new FailedEditPaymentStub($this->twoModel(), [
            'buyer' => [
                'company' => ['company_name' => 'Buyer Company', 'organization_number' => '123456789'],
                'representative' => ['phone_number' => '+4712345678'],
            ],
            'terms' => ['type' => 'NET_TERMS', 'duration_days' => 30],
        ]));

        // The adapter's shape for a non-2xx response whose body is not JSON.
        $adapter = $this->createMock(Adapter::class);
        $adapter->method('execute')->willReturn(['http_status' => $status]);

        $composeOrder = $this->getMockBuilder(ComposeOrder::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['execute'])
            ->getMock();
        $composeOrder->method('execute')->willReturn(['order_reference' => 'order-reference']);
        $orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $orderRepository->method('get')->willReturn($order);
        $overlayRegistry = $this->createMock(BrandOverlayRegistryInterface::class);
        $overlayRegistry->method('isTwoStackMethod')->willReturn(true);
        $postprocessor = $this->createMock(OrderPostprocessor::class);
        $postprocessor->method('process')->willReturnArgument(1);
        $warnings = [];
        $messageManager = $this->createMock(ManagerInterface::class);
        $messageManager->method('addWarningMessage')->willReturnCallback(
            function ($message) use (&$warnings, $messageManager) {
                $warnings[] = (string)$message;
                return $messageManager;
            }
        );

        (new SalesOrderAddressUpdate(
            $this->createMock(ConfigRepository::class),
            $this->brandRegistry(),
            $orderRepository,
            $composeOrder,
            $adapter,
            $overlayRegistry,
            $messageManager,
            $postprocessor
        ))->execute(new AddressUpdateObserverStub(new AddressUpdateEventStub(42)));

        $history = array_map('strval', $order->historyComments);
        $this->assertCount(1, $history, $description);
        $this->assertStringNotContainsString('accepted', $history[0], $description);
        $this->assertStringContainsString('failed', $history[0], $description);
        $this->assertStringContainsString((string)$status, $history[0], $description);
        $this->assertSame($history, $warnings, $description . ': the admin is warned');
    }

    private function twoModel(): Two
    {
        $model = $this->getMockBuilder(Two::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getMock();
        $ref = new \ReflectionClass(Two::class);
        foreach (['configRepository' => $this->createMock(ConfigRepository::class),
                     'brandRegistry' => $this->brandRegistry()] as $name => $value) {
            $prop = $ref->getProperty($name);
            $prop->setAccessible(true);
            $prop->setValue($model, $value);
        }

        return $model;
    }

    private function brandRegistry(): BrandRegistryInterface
    {
        $brand = $this->createMock(BrandRegistryInterface::class);
        $brand->method('getProductName')->willReturn('Test Product');

        return $brand;
    }
}

class FailedEditPaymentStub
{
    /** @var Two */
    private $methodInstance;

    /** @var array */
    private $additionalInformation;

    public function __construct(Two $methodInstance, array $additionalInformation)
    {
        $this->methodInstance = $methodInstance;
        $this->additionalInformation = $additionalInformation;
    }

    public function getMethod(): string
    {
        return 'two_payment';
    }

    public function getAdditionalInformation(): array
    {
        return $this->additionalInformation;
    }

    public function getMethodInstance(): Two
    {
        return $this->methodInstance;
    }
}
