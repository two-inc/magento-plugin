<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Service\Order;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Url;
use Magento\Sales\Api\OrderRepositoryInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\BrandOverlayRegistryInterface;
use Two\Gateway\Api\BrandRegistryInterface;
use Two\Gateway\Api\Config\RepositoryInterface as ConfigRepository;
use Two\Gateway\Api\Log\RepositoryInterface as LogRepository;
use Two\Gateway\Observer\SalesOrderAddressUpdate;
use Two\Gateway\Service\Api\Adapter;
use Two\Gateway\Service\Order\ComposeOrder;
use Two\Gateway\Service\Order\OrderPostprocessor;
use Two\Gateway\Test\Unit\Observer\AddressUpdateEventStub;
use Two\Gateway\Test\Unit\Observer\AddressUpdateObserverStub;
use Two\Gateway\Test\Unit\Observer\AddressUpdateOrderStub;
use Two\Gateway\Test\Unit\Observer\AddressUpdatePaymentStub;

require_once __DIR__ . '/../../Observer/SalesOrderAddressUpdateOptionalFieldsTest.php';

/**
 * TWO-26277: the order-level discount_amount on the create and the address
 * edit request. Magento stores the order's discount negative, unlike the
 * positive item and shipping discounts, so every order with a cart-rule
 * discount used to be refused by the negative-discount guard (TWO-25099).
 *
 * Nothing discount-related is stubbed: the order carries Magento's own fields
 * and the real getter computes what is sent.
 */
class ComposeOrderDiscountAmountTest extends TestCase
{
    /**
     * Magento order fields (discount_amount stored negative), and the sent value.
     */
    public static function discountCases(): array
    {
        // [order fields, expected discount_amount, description]
        return [
            [
                ['discount_amount' => -20.00, 'coupon_code' => 'SAVE10', 'discount_description' => 'SAVE10'],
                '20.00',
                'coupon: 10% off 200.00 of items',
            ],
            [
                ['discount_amount' => -15.50, 'discount_description' => 'Spend 150, save 15.50'],
                '15.50',
                'automatic cart rule, no coupon',
            ],
            [
                ['discount_amount' => -5.00, 'shipping_discount_amount' => 5.00],
                '5.00',
                'shipping discount only',
            ],
            [
                ['discount_amount' => -24.20, 'discount_tax_compensation_amount' => 4.20],
                '20.00',
                'tax-inclusive prices: discount less its tax compensation',
            ],
            [
                [],
                '0.00',
                'no discount',
            ],
            [
                ['discount_amount' => -0.004],
                '0.00',
                'sub-cent discount rounds to zero',
            ],
        ];
    }

    #[DataProvider('discountCases')]
    public function testCreateRequestSendsTheOrderDiscountAsAPositiveAmount(
        array $orderFields,
        string $expected,
        string $description
    ): void {
        $order = $this->makeOrder($orderFields);

        $payload = $this->makeComposeOrder()->execute($order, 'ref', []);

        $this->assertSame($expected, $payload['discount_amount'], $description);
    }

    #[DataProvider('discountCases')]
    public function testAddressEditRequestSendsTheOrderDiscountAsAPositiveAmount(
        array $orderFields,
        string $expected,
        string $description
    ): void {
        $order = $this->makeOrder($orderFields);
        $order->setData('two_order_id', 'remote-order-id');
        $order->setData('two_order_reference', 'order-reference');
        $order->setData('payment', new AddressUpdatePaymentStub([
            'buyer' => [
                'company' => ['company_name' => 'Buyer Company', 'organization_number' => '123456789'],
                'representative' => ['phone_number' => '+4712345678'],
            ],
            'terms' => ['type' => 'NET_TERMS', 'duration_days' => 30],
        ]));

        $sent = null;
        $adapter = $this->createMock(Adapter::class);
        $adapter->method('execute')->willReturnCallback(
            function (string $endpoint, array $payload = [], string $method = 'POST') use (&$sent): array {
                if ($method === 'GET') {
                    return ['state' => 'CONFIRMED'];
                }
                $sent = $payload;
                return ['id' => 'remote-order-id'];
            }
        );
        $orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $orderRepository->method('get')->willReturn($order);
        $overlayRegistry = $this->createMock(BrandOverlayRegistryInterface::class);
        $overlayRegistry->method('isTwoStackMethod')->willReturn(true);
        $postprocessor = $this->createMock(OrderPostprocessor::class);
        $postprocessor->method('process')->willReturnArgument(1);

        $observer = new SalesOrderAddressUpdate(
            $this->createMock(ConfigRepository::class),
            $this->createMock(BrandRegistryInterface::class),
            $orderRepository,
            $this->makeComposeOrder(),
            $adapter,
            $overlayRegistry,
            $this->createMock(\Magento\Framework\Message\ManagerInterface::class),
            $postprocessor
        );
        $observer->execute(new AddressUpdateObserverStub(new AddressUpdateEventStub(42)));

        $this->assertNotNull(
            $sent,
            $description . ': edit request sent; history: ' . implode(' | ', $order->historyComments)
        );
        $this->assertSame($expected, $sent['discount_amount'], $description);
    }

    /**
     * A positive stored order discount has the wrong sign for Magento, so it
     * is refused like a negative item discount rather than sent.
     */
    public function testWrongSignStoredOrderDiscountIsRefused(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Negative discount amount -20.000000 for order 100000001');

        $this->makeComposeOrder()->execute($this->makeOrder(['discount_amount' => 20.00]), 'ref', []);
    }

    private function makeOrder(array $fields): AddressUpdateOrderStub
    {
        $order = new AddressUpdateOrderStub();
        $fields += [
            'store_id' => 1,
            'grand_total' => 100.00,
            'tax_amount' => 20.00,
            'order_currency_code' => 'EUR',
            'increment_id' => '100000001',
        ];
        foreach ($fields as $key => $value) {
            $order->setData($key, $value);
        }

        return $order;
    }

    /** @return ComposeOrder|\PHPUnit\Framework\MockObject\MockObject */
    private function makeComposeOrder()
    {
        $composeOrder = $this->getMockBuilder(ComposeOrder::class)
            ->disableOriginalConstructor()
            ->onlyMethods([
                'getLineItemsOrder',
                'getAddress',
                'getBuyer',
                'getTaxSubtotals',
                'getFeeLines',
                'getOtherChargesLineItem',
            ])
            ->getMock();
        $composeOrder->method('getLineItemsOrder')->willReturn([]);
        $composeOrder->method('getAddress')->willReturn([]);
        $composeOrder->method('getBuyer')->willReturn([]);
        $composeOrder->method('getTaxSubtotals')->willReturn([]);
        $composeOrder->method('getFeeLines')->willReturn([]);
        $composeOrder->method('getOtherChargesLineItem')->willReturn(null);

        $config = $this->createMock(ConfigRepository::class);
        $config->method('getVendorSiteName')->willReturn('');
        $config->method('getAllBuyerTerms')->willReturn([30]);
        $config->method('isBuyerTermAvailable')->willReturn(true);
        $config->method('getDefaultPaymentTerm')->willReturn(30);
        $config->method('getPaymentTermsType')->willReturn('invoice_date');
        $composeOrder->configRepository = $config;
        $composeOrder->url = $this->createMock(Url::class);
        $composeOrder->url->method('getUrl')->willReturn('https://example.test/two');

        $log = new \ReflectionProperty(\Two\Gateway\Service\Order::class, 'logRepository');
        $log->setValue($composeOrder, $this->createMock(LogRepository::class));
        $session = new \ReflectionProperty(ComposeOrder::class, 'checkoutSession');
        $session->setValue($composeOrder, new \Magento\Checkout\Model\Session());

        return $composeOrder;
    }
}
