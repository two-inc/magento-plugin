<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Service\Order;

use Magento\Sales\Model\Order as OrderModel;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\Log\RepositoryInterface as LogRepository;
use Two\Gateway\Service\Order;

/**
 * getOtherChargesLineItem()'s SECOND tier: a taxed residual that no
 * FeeLineProviderInterface recognized can still be reconciled if Magento's
 * own tax engine already applied a rate that matches it.
 *
 * Magento copies the quote address's `applied_taxes` total data onto the
 * order's own extension attributes during quote-to-order conversion —
 * before Order::place() ever runs — so any well-behaved total-collector
 * extension (Magento's own Weee, or a third-party fee module like
 * Amasty's Extra Fee that integrates with the tax engine properly) shows
 * up here with no vendor-specific code needed.
 *
 * Each entry's shape depends on exactly when it's read: right after the
 * quote-to-order conversion plugin it's a plain array, but
 * QuoteManagement::submitQuote() immediately re-merges that converted
 * order into a fresh one via DataObjectHelper::mergeDataObjects() — which
 * rehydrates the array into Magento\Tax\Model\Sales\Order\Tax objects
 * (confirmed live: this is the shape ComposeOrder's $entity actually
 * carries). Both shapes are covered here — this isn't defensive padding
 * for a case that can't happen, both are real.
 */
class VerifiedResidualTaxRateTest extends TestCase
{
    /** @var Order|\PHPUnit\Framework\MockObject\MockObject */
    private $orderService;

    /** @var LogRepository|\PHPUnit\Framework\MockObject\MockObject */
    private $logRepository;

    protected function setUp(): void
    {
        $this->orderService = $this->getMockForAbstractClass(
            Order::class,
            [],
            '',
            false // don't call constructor
        );
        $this->logRepository = $this->createMock(LogRepository::class);

        // Constructor is skipped, so inject the logger directly.
        $property = new \ReflectionProperty(Order::class, 'logRepository');
        $property->setValue($this->orderService, $this->logRepository);
    }

    private function productLine(string $gross, string $tax): array
    {
        return [
            'order_item_id' => '1',
            'gross_amount' => $gross,
            'tax_amount' => $tax,
        ];
    }

    private function orderWithAppliedTaxes(array $appliedTaxes): OrderModel
    {
        $order = new OrderModel();
        $order->setExtensionAttributes(new class ($appliedTaxes) {
            private array $appliedTaxes;

            public function __construct(array $appliedTaxes)
            {
                $this->appliedTaxes = $appliedTaxes;
            }

            public function getAppliedTaxes(): array
            {
                return $this->appliedTaxes;
            }
        });

        return $order;
    }

    /**
     * The real-world shape: what ComposeOrder's $entity actually carries
     * post-mergeDataObjects() (see class docblock), stood in for here by
     * a minimal object exposing just getPercent() — the only accessor
     * findVerifiedResidualTaxRate() calls.
     */
    private function appliedTaxObject(float $percent): object
    {
        return new class ($percent) {
            private float $percent;

            public function __construct(float $percent)
            {
                $this->percent = $percent;
            }

            public function getPercent(): float
            {
                return $this->percent;
            }
        };
    }

    /**
     * @dataProvider verifiedRateScenarioProvider
     */
    public function testTaxedResidualMatchingAnAppliedRateIsItemized(
        array $appliedTaxes,
        string $expectedTaxRate,
        string $expectedTaxClassName,
        string $description
    ): void {
        $this->logRepository->expects($this->never())->method('addErrorLog');

        $lineItems = [
            $this->productLine('100.00', '20.00'), // e.g. product incl. VAT
        ];
        $order = $this->orderWithAppliedTaxes($appliedTaxes);

        // Simulated third-party fee (e.g. Amasty Extra Fee) that DOES
        // register its tax with Magento's tax engine: net 10.00 + tax
        // 2.00 = gross 12.00, folded into grand_total/tax_amount by the
        // extension's totals collector but absent from line_items.
        $grandTotal = 100.00 + 12.00; // 112.00
        $taxTotal = 20.00 + 2.00;     // 22.00

        $result = $this->orderService->getOtherChargesLineItem($lineItems, $order, $grandTotal, $taxTotal);

        $this->assertNotNull($result, $description);
        $this->assertSame('other_charges', $result['order_item_id'], $description);
        $this->assertSame('12.00', $result['gross_amount'], $description);
        $this->assertSame('10.00', $result['net_amount'], $description);
        $this->assertSame('2.00', $result['tax_amount'], $description);
        $this->assertSame($expectedTaxRate, $result['tax_rate'], $description);
        $this->assertSame($expectedTaxClassName, $result['tax_class_name'], $description);
    }

    /** @return array<string, array{array, string, string, string}> */
    public function verifiedRateScenarioProvider(): array
    {
        return [
            'single applied rate matches exactly (array shape)' => [
                [['percent' => 20]],
                '0.200000',
                'VAT 20.00%',
                'the only applied rate on the order reconciles the residual',
            ],
            'one of several applied rates matches (array shape)' => [
                [['percent' => 5], ['percent' => 20], ['percent' => 17.5]],
                '0.200000',
                'VAT 20.00%',
                'must find the matching rate, not just try the first one',
            ],
            'single applied rate matches exactly (object shape)' => [
                [$this->appliedTaxObject(20)],
                '0.200000',
                'VAT 20.00%',
                'the real-world post-mergeDataObjects() shape ComposeOrder actually sees',
            ],
            'one of several applied rates matches (object shape)' => [
                [$this->appliedTaxObject(5), $this->appliedTaxObject(20), $this->appliedTaxObject(17.5)],
                '0.200000',
                'VAT 20.00%',
                'must find the matching rate among objects too, not just try the first one',
            ],
        ];
    }

    public function testTaxedResidualNotMatchingAnyAppliedRateIsStillLoggedNotGuessed(): void
    {
        $this->logRepository->expects($this->once())
            ->method('addErrorLog')
            ->with('UnreconciledOtherCharges', $this->isType('string'));

        $lineItems = [
            $this->productLine('100.00', '20.00'),
        ];
        // Order has applied taxes, but none of them explain a 2.00 tax on
        // a 10.00 net residual (which would require a 20% rate).
        $order = $this->orderWithAppliedTaxes([['percent' => 5]]);

        $result = $this->orderService->getOtherChargesLineItem($lineItems, $order, 112.00, 22.00);

        $this->assertNull($result);
    }

    public function testOrderWithNoAppliedTaxesIsStillLoggedNotGuessed(): void
    {
        $this->logRepository->expects($this->once())
            ->method('addErrorLog')
            ->with('UnreconciledOtherCharges', $this->isType('string'));

        $lineItems = [
            $this->productLine('100.00', '20.00'),
        ];
        $order = $this->orderWithAppliedTaxes([]);

        $result = $this->orderService->getOtherChargesLineItem($lineItems, $order, 112.00, 22.00);

        $this->assertNull($result);
    }

    /**
     * Only the order carries the appliedTaxes extension attribute, so an
     * invoice or credit memo is resolved to its own order and reads the rate
     * from there. A residual on either is a share of the same order-level fee
     * at the same rate, which is what makes the refund/capture payloads
     * reconcile it as generically as placement does.
     *
     * @dataProvider entityProvider
     */
    public function testEveryEntityReachesTheOrdersAppliedRates(string $entityType, string $description): void
    {
        $this->logRepository->expects($this->never())->method('addErrorLog');

        $order = $this->orderWithAppliedTaxes([$this->appliedTaxObject(20.0)]);
        $entity = $this->wrapOrder($order, $entityType);

        $lineItems = [
            $this->productLine('100.00', '20.00'),
        ];

        $result = $this->orderService->getOtherChargesLineItem($lineItems, $entity, 112.00, 22.00);

        $this->assertNotNull($result, $description);
        $this->assertSame('10.00', $result['net_amount'], $description);
        $this->assertSame('2.00', $result['tax_amount'], $description);
        $this->assertSame('0.200000', $result['tax_rate'], $description);
    }

    public static function entityProvider(): array
    {
        return [
            ['order', 'order reads its own applied rates'],
            ['invoice', 'invoice resolves to its order'],
            ['creditmemo', 'creditmemo resolves to its order'],
        ];
    }

    /**
     * @dataProvider orderlessEntityProvider
     */
    public function testAnEntityWithNoOrderStillRefusesToGuess(string $entityType, string $description): void
    {
        $this->logRepository->expects($this->once())
            ->method('addErrorLog')
            ->with('UnreconciledOtherCharges', $this->isType('string'));

        $lineItems = [
            $this->productLine('100.00', '20.00'),
        ];
        $entity = $this->wrapOrder(null, $entityType);

        $result = $this->orderService->getOtherChargesLineItem($lineItems, $entity, 112.00, 22.00);

        $this->assertNull($result, $description);
    }

    public static function orderlessEntityProvider(): array
    {
        return [
            ['invoice', 'invoice with no order'],
            ['creditmemo', 'creditmemo with no order'],
            ['foreign', 'an entity type this path does not serve'],
        ];
    }

    /**
     * The admin invoice and credit-memo controllers load the order through
     * OrderFactory, which never populates the applied_taxes extension
     * attribute — so the persisted tax rows are the only source there, and a
     * taxed fee is unrefundable without this fallback.
     *
     * @dataProvider persistedRateEntityProvider
     */
    public function testAPersistedTaxRowSuppliesTheRateWhenTheAttributeIsEmpty(
        string $entityType,
        string $description
    ): void {
        $this->logRepository->expects($this->never())->method('addErrorLog');

        $order = new OrderModel();
        $order->setData('id', 42);
        $this->givenPersistedAppliedTaxPercent(42, 20.0);

        $lineItems = [$this->productLine('100.00', '20.00')];

        $result = $this->orderService->getOtherChargesLineItem(
            $lineItems,
            $this->wrapOrder($order, $entityType),
            112.00,
            22.00
        );

        $this->assertNotNull($result, $description);
        $this->assertSame('10.00', $result['net_amount'], $description);
        $this->assertSame('2.00', $result['tax_amount'], $description);
        $this->assertSame('0.200000', $result['tax_rate'], $description);
    }

    public static function persistedRateEntityProvider(): array
    {
        return [
            ['order', 'order with no extension attribute'],
            ['invoice', 'invoice, as the admin invoice screen loads it'],
            ['creditmemo', 'creditmemo, as the admin refund screen loads it'],
        ];
    }

    public function testAnOrderWithNeitherSourceStillRefusesToGuess(): void
    {
        $this->logRepository->expects($this->once())
            ->method('addErrorLog')
            ->with('UnreconciledOtherCharges', $this->isType('string'));

        $order = new OrderModel();
        $order->setData('id', 42);
        $this->givenPersistedAppliedTaxPercent(42, null);

        $lineItems = [$this->productLine('100.00', '20.00')];

        $result = $this->orderService->getOtherChargesLineItem($lineItems, $order, 112.00, 22.00);

        $this->assertNull($result);
    }

    private function givenPersistedAppliedTaxPercent(int $orderId, ?float $percent): void
    {
        $applied = $percent === null ? [] : [$this->appliedTaxObject($percent)];
        $details = $this->createMock(\Magento\Tax\Api\Data\OrderTaxDetailsInterface::class);
        $details->method('getAppliedTaxes')->willReturn($applied);
        $management = $this->createMock(\Magento\Tax\Api\OrderTaxManagementInterface::class);
        $management->method('getOrderTaxDetails')->with($orderId)->willReturn($details);

        $property = new \ReflectionProperty(Order::class, 'orderTaxManagement');
        $property->setValue($this->orderService, $management);
    }

    /**
     * @param OrderModel|null $order
     * @return mixed
     */
    private function wrapOrder($order, string $entityType)
    {
        switch ($entityType) {
            case 'order':
                return $order;
            case 'invoice':
                return (new OrderModel\Invoice())->setOrder($order);
            case 'creditmemo':
                return (new OrderModel\Creditmemo())->setOrder($order);
            default:
                return new \stdClass();
        }
    }
}
