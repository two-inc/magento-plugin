<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Observer;

use Magento\Framework\Message\ManagerInterface;
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

require_once __DIR__ . '/SalesOrderAddressUpdateOptionalFieldsTest.php';

/**
 * TWO-26086: an admin address edit re-sends the order, and the term on that
 * request must be the one the order was placed on, never the live config.
 * Runs the real ComposeOrder so the term in the outgoing PUT is asserted.
 */
class SalesOrderAddressUpdateTermTest extends TestCase
{
    private const NET_30 = ['type' => 'NET_TERMS', 'duration_days' => 30];
    private const NET_60 = ['type' => 'NET_TERMS', 'duration_days' => 60];
    private const NET_60_EOM = ['type' => 'NET_TERMS', 'duration_days' => 60, 'duration_days_calculated_from' => 'END_OF_MONTH'];
    private const OMITTED = 'omitted';
    private const REFUSED = 'refused';

    public static function termCases(): array
    {
        // stored terms (null: key absent), offered now, default now, type now, expected PUT terms, API error (null: accepted), description
        return [
            [self::NET_60, [30, 60], 30, 'invoice_date', self::NET_60, null, 'chosen term differs from the default'],
            [self::NET_30, [60, 90], 60, 'invoice_date', self::NET_30, null, 'default changed and old term withdrawn after the order'],
            [self::NET_60, [30, 60], 30, 'end_of_month', self::NET_60, null, 'term type changed to end of month after the order'],
            [self::NET_60_EOM, [30, 60], 30, 'invoice_date', self::NET_60_EOM, null, 'stored duration_days_calculated_from is kept'],
            [null, [30, 60], 30, 'invoice_date', self::OMITTED, null, 'legacy order with no stored term is sent without terms'],
            ['NET_30', [30, 60], 30, 'invoice_date', self::OMITTED, null, 'stored terms that are not an array are treated as legacy'],
            [['type' => 'NET_TERMS', 'duration_days' => 0], [30, 60], 30, 'invoice_date', self::REFUSED, null, 'stored term of 0 days is refused'],
            [self::NET_60, [30, 60], 30, 'invoice_date', self::NET_60, 'Edit rejected by Two', 'API error response warns the admin'],
        ];
    }

    #[DataProvider('termCases')]
    public function testEditRequestCarriesTheTermTheOrderWasPlacedOn(
        $storedTerms,
        array $offeredTerms,
        int $defaultTerm,
        string $termsType,
        $expectedTerms,
        ?string $apiError,
        string $description
    ): void {
        $stored = [
            'buyer' => [
                'company' => ['company_name' => 'Buyer Company', 'organization_number' => '123456789'],
                'representative' => ['phone_number' => '+4712345678'],
            ],
        ];
        if ($storedTerms !== null) {
            $stored['terms'] = $storedTerms;
        }
        $order = new AddressUpdateOrderStub();
        $order->setData('store_id', 1);
        $order->setData('two_order_id', 'remote-order-id');
        $order->setData('two_order_reference', 'order-reference');
        $order->setData('grand_total', 100.00);
        $order->setData('tax_amount', 20.00);
        $order->setData('order_currency_code', 'EUR');
        $order->setData('increment_id', '100000001');
        $order->setData('payment', new AddressUpdatePaymentStub($stored));

        $sent = null;
        $adapter = $this->createMock(Adapter::class);
        $adapter->method('execute')->willReturnCallback(
            function (string $endpoint, array $payload = []) use (&$sent, $apiError): array {
                $sent = $payload;
                return $apiError === null ? ['id' => 'remote-order-id'] : ['error_code' => 400, 'error_message' => $apiError];
            }
        );

        $orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $orderRepository->method('get')->willReturn($order);
        $overlayRegistry = $this->createMock(BrandOverlayRegistryInterface::class);
        $overlayRegistry->method('isTwoStackMethod')->willReturn(true);
        $brandRegistry = $this->createMock(BrandRegistryInterface::class);
        $brandRegistry->method('getProductName')->willReturn('Test Product');

        $warnings = [];
        $messageManager = $this->createMock(ManagerInterface::class);
        $messageManager->method('addWarningMessage')->willReturnCallback(
            function ($message) use (&$warnings, $messageManager) {
                $warnings[] = (string)$message;
                return $messageManager;
            }
        );

        $observer = new SalesOrderAddressUpdate(
            $this->createMock(ConfigRepository::class),
            $brandRegistry,
            $orderRepository,
            $this->makeComposeOrder($offeredTerms, $defaultTerm, $termsType),
            $adapter,
            $overlayRegistry,
            $messageManager
        );
        $observer->execute(new AddressUpdateObserverStub(new AddressUpdateEventStub(42)));

        if ($expectedTerms === self::REFUSED) {
            $this->assertNull($sent, $description . ': no edit request is sent');
            $this->assertCount(1, $order->historyComments, $description . ': one history note');
            $this->assertStringContainsString('payment term', $order->historyComments[0], $description);
            $this->assertSame($order->historyComments, $warnings, $description . ': admin warned');
            return;
        }
        $this->assertNotNull($sent, $description . ': edit request sent; history: ' . implode(' | ', $order->historyComments));
        $this->assertArrayNotHasKey('available_terms', $sent, $description . ': not in the edit schema');
        $this->assertSame($apiError === null ? [] : [$apiError], $warnings, $description . ': admin warning');
        if ($apiError !== null) {
            $this->assertSame([$apiError], $order->historyComments, $description . ': history note');
        }
        if ($expectedTerms === self::OMITTED) {
            $this->assertArrayNotHasKey('terms', $sent, $description);
            return;
        }
        $this->assertSame($expectedTerms, $sent['terms'], $description);
    }

    /** @return ComposeOrder|\PHPUnit\Framework\MockObject\MockObject */
    private function makeComposeOrder(array $offeredTerms, int $defaultTerm, string $termsType)
    {
        $composeOrder = $this->getMockBuilder(ComposeOrder::class)
            ->disableOriginalConstructor()
            ->onlyMethods([
                'getLineItemsOrder',
                'getAddress',
                'getBuyer',
                'getTaxSubtotals',
                'getDiscountAmountItem',
                'getFeeLines',
                'getOtherChargesLineItem',
            ])
            ->getMock();
        $composeOrder->method('getLineItemsOrder')->willReturn([]);
        $composeOrder->method('getAddress')->willReturn([]);
        $composeOrder->method('getBuyer')->willReturn([]);
        $composeOrder->method('getTaxSubtotals')->willReturn([]);
        $composeOrder->method('getDiscountAmountItem')->willReturn(0.0);
        $composeOrder->method('getFeeLines')->willReturn([]);
        $composeOrder->method('getOtherChargesLineItem')->willReturn(null);

        $config = $this->createMock(ConfigRepository::class);
        $config->method('getVendorSiteName')->willReturn('');
        $config->method('getPaymentTermsType')->willReturn($termsType);
        $config->method('getDefaultPaymentTerm')->willReturn($defaultTerm);
        $config->method('getAllBuyerTerms')->willReturn($offeredTerms);
        $config->method('isBuyerTermAvailable')->willReturnCallback(
            static fn (int $days): bool => in_array($days, $offeredTerms, true)
        );
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
