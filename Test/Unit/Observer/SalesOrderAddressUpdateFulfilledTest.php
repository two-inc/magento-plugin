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
use Two\Gateway\Observer\SalesOrderAddressUpdate;
use Two\Gateway\Service\Api\Adapter;
use Two\Gateway\Service\Order\ComposeOrder;
use Two\Gateway\Service\Order\OrderPostprocessor;

require_once __DIR__ . '/SalesOrderAddressUpdateOptionalFieldsTest.php';

/**
 * TWO-26150: an address edit on an order the provider has already invoiced is
 * not sent, because the API refuses it. The admin gets a plain notice instead
 * of a refusal warning. A partially fulfilled order is still sent.
 */
class SalesOrderAddressUpdateFulfilledTest extends TestCase
{
    private const NOTICE = 'Test Product has already invoiced this order, so this change was not sent to Test Product.';

    /**
     * @return array<int, array{array, bool, string}> [state lookup response, edit sent, description]
     */
    public static function fulfilmentCases(): array
    {
        return [
            [['state' => 'CONFIRMED', 'status' => 'APPROVED'], true, 'unfulfilled order sends the edit'],
            [['state' => 'CONFIRMED', 'status' => 'PARTIAL'], true, 'partially fulfilled order sends the edit'],
            [['state' => 'FULFILLED', 'status' => 'APPROVED'], false, 'fully fulfilled order skips the edit'],
            [['state' => 'FULFILLED', 'status' => 'PARTIAL'], false, 'order fulfilled in parts, now complete, skips the edit'],
            [['error_message' => 'lookup failed'], true, 'failed state lookup still sends the edit'],
        ];
    }

    #[DataProvider('fulfilmentCases')]
    public function testEditIsSkippedOnlyOnceTheOrderIsFullyFulfilled(
        array $lookup,
        bool $editSent,
        string $description
    ): void {
        $order = new AddressUpdateOrderStub();
        $order->setData('store_id', 1);
        $order->setData('two_order_id', 'remote-order-id');
        $order->setData('two_order_reference', 'order-reference');
        $order->setData('payment', new AddressUpdatePaymentStub([
            'buyer' => [
                'company' => ['company_name' => 'Buyer Company', 'organization_number' => '123456789'],
                'representative' => ['phone_number' => '+4712345678'],
            ],
            'terms' => ['type' => 'NET_TERMS', 'duration_days' => 30],
        ]));

        $puts = 0;
        $adapter = $this->createMock(Adapter::class);
        $adapter->method('execute')->willReturnCallback(
            function (string $endpoint, array $payload = [], string $method = 'POST') use ($lookup, &$puts): array {
                if ($method === 'GET') {
                    return $lookup;
                }
                $puts++;
                return ['id' => 'remote-order-id'];
            }
        );

        $composeOrder = $this->getMockBuilder(ComposeOrder::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['execute'])
            ->getMock();
        $composeOrder->method('execute')->willReturn(['order_reference' => 'order-reference']);
        $orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $orderRepository->method('get')->willReturn($order);
        $overlayRegistry = $this->createMock(BrandOverlayRegistryInterface::class);
        $overlayRegistry->method('isTwoStackMethod')->willReturn(true);
        $brandRegistry = $this->createMock(BrandRegistryInterface::class);
        $brandRegistry->method('getProductName')->willReturn('Test Product');
        $postprocessor = $this->createMock(OrderPostprocessor::class);
        $postprocessor->method('process')->willReturnArgument(1);
        $messages = [];
        $messageManager = $this->createMock(ManagerInterface::class);
        foreach (['addNoticeMessage' => 'notice', 'addWarningMessage' => 'warning'] as $method => $kind) {
            $messageManager->method($method)->willReturnCallback(
                function ($message) use (&$messages, $kind, $messageManager) {
                    $messages[] = $kind . ': ' . $message;
                    return $messageManager;
                }
            );
        }

        (new SalesOrderAddressUpdate(
            $this->createMock(ConfigRepository::class),
            $brandRegistry,
            $orderRepository,
            $composeOrder,
            $adapter,
            $overlayRegistry,
            $messageManager,
            $postprocessor
        ))->execute(new AddressUpdateObserverStub(new AddressUpdateEventStub(42)));

        $history = array_map('strval', $order->historyComments);
        $this->assertSame(1, $order->saveCount, $description . ': the order is saved once');
        if ($editSent) {
            $this->assertSame(1, $puts, $description . ': one edit request');
            $this->assertSame([], $messages, $description . ': no admin message');
            $this->assertCount(1, $history, $description);
            $this->assertStringContainsString('accepted', $history[0], $description);
            return;
        }
        $this->assertSame(0, $puts, $description . ': no edit request');
        $this->assertSame(['notice: ' . self::NOTICE], $messages, $description . ': admin notice');
        $this->assertSame([self::NOTICE], $history, $description . ': history comment');
    }
}
