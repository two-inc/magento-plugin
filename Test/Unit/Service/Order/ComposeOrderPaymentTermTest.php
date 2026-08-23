<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Service\Order;

use Magento\Framework\Exception\InputException;
use Magento\Framework\Url;
use Magento\Sales\Model\Order;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\Config\RepositoryInterface as ConfigRepository;
use Two\Gateway\Api\Log\RepositoryInterface as LogRepository;
use Two\Gateway\Service\Order\ComposeOrder;

/**
 * TWO-25503: a payment term the buyer selected but the merchant no longer
 * offers must block the order, not be swapped for the default. The buyer
 * agreed to pay on a specific term; placing the order on another one is a
 * contract they never saw. Same check and exception as the chip-click
 * endpoint (Model\Webapi\TermSelection).
 *
 * Built the same way as ComposeOrderOptionalFieldsTest so execute() runs its
 * real implementation.
 */
class ComposeOrderPaymentTermTest extends TestCase
{
    /** @var ConfigRepository|\PHPUnit\Framework\MockObject\MockObject */
    private $configRepository;

    /** @var LogRepository|\PHPUnit\Framework\MockObject\MockObject */
    private $logRepository;

    /**
     * @param array $availableTerms terms the merchant currently offers
     * @return ComposeOrder|\PHPUnit\Framework\MockObject\MockObject
     */
    private function makeComposeOrder(array $availableTerms)
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

        $this->configRepository = $this->createMock(ConfigRepository::class);
        $this->configRepository->method('getVendorSiteName')->willReturn('');
        $this->configRepository->method('getPaymentTermsType')->willReturn('invoice_date');
        $this->configRepository->method('getDefaultPaymentTerm')->willReturn(30);
        $this->configRepository->method('getAllBuyerTerms')->willReturn($availableTerms);
        $this->configRepository->method('isBuyerTermAvailable')
            ->willReturnCallback(static function (int $termDays) use ($availableTerms): bool {
                return in_array($termDays, $availableTerms, true);
            });

        $composeOrder->configRepository = $this->configRepository;
        $composeOrder->url = $this->createMock(Url::class);
        $composeOrder->url->method('getUrl')->willReturn('https://example.test/two');

        $this->logRepository = $this->createMock(LogRepository::class);
        $logProperty = new \ReflectionProperty(\Two\Gateway\Service\Order::class, 'logRepository');
        $logProperty->setAccessible(true);
        $logProperty->setValue($composeOrder, $this->logRepository);

        $sessionProperty = new \ReflectionProperty(ComposeOrder::class, 'checkoutSession');
        $sessionProperty->setAccessible(true);
        $sessionProperty->setValue($composeOrder, new \Magento\Checkout\Model\Session());

        return $composeOrder;
    }

    private function makeOrder(): Order
    {
        $order = new Order();
        $order->setStoreId(1);
        $order->setGrandTotal(100.00);
        $order->setTaxAmount(20.00);
        $order->setOrderCurrencyCode('EUR');
        $order->setIncrementId('100000001');

        return $order;
    }

    public function testAnAvailableSelectedTermIsSent(): void
    {
        $payload = $this->makeComposeOrder([14, 30])
            ->execute($this->makeOrder(), 'ref', ['selectedTerm' => 14]);

        $this->assertSame(14, $payload['terms']['duration_days']);
    }

    public function testAnUnavailableSelectedTermBlocksTheOrder(): void
    {
        $composeOrder = $this->makeComposeOrder([14, 30]);
        $this->logRepository->expects($this->once())->method('addErrorLog');

        $this->expectException(InputException::class);
        $this->expectExceptionMessage('Selected payment term is not available.');
        $composeOrder->execute($this->makeOrder(), 'ref', ['selectedTerm' => 90]);
    }

    /**
     * No selection is not a failed selection: the checkout never sent one, so
     * the configured default applies.
     */
    public function testNoSelectionFallsBackToTheDefaultTerm(): void
    {
        $payload = $this->makeComposeOrder([14, 30])->execute($this->makeOrder(), 'ref', []);

        $this->assertSame(30, $payload['terms']['duration_days']);
    }
}
