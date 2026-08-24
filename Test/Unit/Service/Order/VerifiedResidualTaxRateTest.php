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

    public function testInvoiceEntityCannotUseThisTierEvenWithATaxedResidual(): void
    {
        // Invoice/Creditmemo don't carry the appliedTaxes extension
        // attribute Magento populates from the quote — only Order does —
        // so a taxed residual on those entities still falls through to
        // the "log and refuse" branch, exactly as before this tier
        // existed. This is a known, accepted gap, not a bug here.
        $this->logRepository->expects($this->once())
            ->method('addErrorLog')
            ->with('UnreconciledOtherCharges', $this->isType('string'));

        $lineItems = [
            $this->productLine('100.00', '20.00'),
        ];
        $invoice = new OrderModel\Invoice();

        $result = $this->orderService->getOtherChargesLineItem($lineItems, $invoice, 112.00, 22.00);

        $this->assertNull($result);
    }
}
