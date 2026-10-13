<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Service\Order;

use Magento\Framework\Url;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Creditmemo;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\Order\Shipment;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\Config\RepositoryInterface as ConfigRepository;
use Two\Gateway\Service\Order\ComposeCapture;
use Two\Gateway\Service\Order\ComposeOrder;
use Two\Gateway\Service\Order\ComposeRefund;
use Two\Gateway\Service\Order\ComposeShipment;

/**
 * TWO-26081: every payload composer must send the per-rate tax breakdown
 * under the Two API key `tax_subtotals` (plural), each entry carrying
 * taxable_amount, tax_amount and tax_rate. ComposeCapture sent it as
 * `tax_subtotal`, which the fulfilments endpoint does not recognise.
 *
 * One row per composer, so a new composer or a renamed key fails here.
 * ComposeOrder covers both create (POST /v1/order) and edit
 * (PUT /v1/order/{id}), which re-composes through the same class.
 *
 * Each composer runs its real execute() and real getTaxSubtotals(); only the
 * line-item getter and fee reconciliation are stubbed, to one fixed line.
 */
class TaxSubtotalsPayloadKeyTest extends TestCase
{
    private const LINE = [
        'name' => 'Widget',
        'gross_amount' => '125.00',
        'net_amount' => '100.00',
        'tax_amount' => '25.00',
        'discount_amount' => '0.00',
        'tax_rate' => '0.250000',
    ];

    private const ORDER_STORE = 3;

    /** @var bool the setting at the order's store; every other scope has the opposite */
    private $atOrderStore = true;

    private const EXPECTED_SUBTOTALS = [
        ['taxable_amount' => '100.00', 'tax_amount' => '25.00', 'tax_rate' => '0.250000'],
    ];

    public static function composerProvider(): array
    {
        return [
            'order create and edit' => ['order'],
            'capture (fulfilment)' => ['capture'],
            'shipment (fulfilment)' => ['shipment'],
            'refund' => ['refund'],
        ];
    }

    /**
     * @dataProvider composerProvider
     */
    public function testPayloadSendsTaxSubtotalsUnderThePluralKey(string $composer): void
    {
        $payload = $this->compose($composer);

        $this->assertArrayNotHasKey('tax_subtotal', $payload, "$composer: singular key sent");
        $this->assertArrayHasKey('tax_subtotals', $payload, "$composer: tax_subtotals missing");
        $this->assertSame(self::EXPECTED_SUBTOTALS, $payload['tax_subtotals'], "$composer: shape");
    }

    /**
     * TWO-26340: "Validate tax subtotals" is read at the order's store, not
     * at default scope. Given the setting differs between the order's store
     * and every other scope; When each composer runs; Then the order's
     * store decides whether the breakdown is sent.
     *
     * @dataProvider storeScopeProvider
     */
    public function testTheSettingIsReadAtTheOrdersStore(string $composer, bool $atOrderStore): void
    {
        $payload = $this->compose($composer, $atOrderStore);

        if ($atOrderStore) {
            $this->assertSame(self::EXPECTED_SUBTOTALS, $payload['tax_subtotals'] ?? null, "$composer: on at the order's store");
        } else {
            // Left out, not sent as null (TWO-26340).
            $this->assertFalse(array_key_exists('tax_subtotals', $payload), "$composer: off at the order's store");
        }
    }

    public static function storeScopeProvider(): array
    {
        $cases = [];
        foreach (self::composerProvider() as $name => [$composer]) {
            $cases["$name, on at the order's store"] = [$composer, true];
            $cases["$name, off at the order's store"] = [$composer, false];
        }
        return $cases;
    }

    private function compose(string $composer, bool $atOrderStore = true): array
    {
        $this->atOrderStore = $atOrderStore;
        $order = new Order();
        $order->setStoreId(self::ORDER_STORE);
        $order->setGrandTotal(125.00);
        $order->setTaxAmount(25.00);
        $order->setOrderCurrencyCode('EUR');
        $order->setIncrementId('100000001');

        switch ($composer) {
            case 'order':
                $service = $this->build(ComposeOrder::class, 'getLineItemsOrder', ['getAddress', 'getBuyer']);
                $service->method('getAddress')->willReturn([]);
                $service->method('getBuyer')->willReturn([]);
                $service->url = $this->createMock(Url::class);
                $service->url->method('getUrl')->willReturn('https://example.test/two');
                $session = new \ReflectionProperty(ComposeOrder::class, 'checkoutSession');
                $session->setValue($service, new \Magento\Checkout\Model\Session());
                return $service->execute($order, 'ref', []);
            case 'capture':
                $invoice = new Invoice();
                $invoice->setOrder($order);
                $invoice->setGrandTotal(125.00);
                $invoice->setTaxAmount(25.00);
                $invoice->setDiscountAmount(0.0);
                return $this->build(ComposeCapture::class, 'getLineItemsInvoice')->execute($invoice);
            case 'shipment':
                return $this->build(ComposeShipment::class, 'getLineItemsShipment')
                    ->execute(new Shipment(), $order);
            case 'refund':
                $creditmemo = new Creditmemo();
                $creditmemo->setGrandTotal(125.00);
                $creditmemo->setTaxAmount(25.00);
                return $this->build(ComposeRefund::class, 'getLineItemsCreditmemo')
                    ->execute($creditmemo, 125.00, $order);
        }
        $this->fail("Unknown composer $composer");
    }

    /**
     * @return \PHPUnit\Framework\MockObject\MockObject
     */
    private function build(string $class, string $lineItemGetter, array $extraStubs = [])
    {
        $service = $this->getMockBuilder($class)
            ->disableOriginalConstructor()
            ->onlyMethods(array_merge([$lineItemGetter, 'getFeeLines', 'getOtherChargesLineItem'], $extraStubs))
            ->getMock();
        $service->method($lineItemGetter)->willReturn([self::LINE]);
        $service->method('getFeeLines')->willReturn([]);
        $service->method('getOtherChargesLineItem')->willReturn(null);

        $config = $this->createMock(ConfigRepository::class);
        $config->method('isTaxSubtotalsEnabled')->willReturnCallback(
            fn ($storeId = null) => $storeId === self::ORDER_STORE ? $this->atOrderStore : !$this->atOrderStore
        );
        $config->method('getVendorSiteName')->willReturn('');
        $config->method('getAllBuyerTerms')->willReturn([30]);
        $config->method('isBuyerTermAvailable')->willReturn(true);
        $config->method('getDefaultPaymentTerm')->willReturn(30);
        $config->method('getPaymentTermsType')->willReturn('invoice_date');
        $service->configRepository = $config;

        return $service;
    }
}
