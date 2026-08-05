<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Service\Order;

use PHPUnit\Framework\TestCase;
use Two\Gateway\Service\Order;

/**
 * Generic catch-all reconciliation for any third-party totals-collector
 * amount (e.g. Amasty's "Extra Fee" module) that bumps grand_total the
 * same way Magento's own shipping total does, without being a
 * quote/order item — so it never appears in line_items even though it
 * is included in the aggregate total we report to Two.
 *
 * Deliberately reconciled against Magento's own aggregate columns
 * (grand/gross total, tax total) rather than any named extension's
 * fields, so it catches this shape from any current or future
 * extension, not just the one that surfaced the bug report.
 *
 * Covers:
 *  (a) an ordinary order/invoice/creditmemo with no untracked total
 *      produces zero synthetic line items (no false positives from
 *      rounding noise);
 *  (b) an entity with an untracked total (simulated: grand_total
 *      exceeds sum(line_items) by more than known items account for)
 *      produces exactly one correctly-computed synthetic line.
 */
class OtherChargesLineItemTest extends TestCase
{
    /** @var Order|\PHPUnit\Framework\MockObject\MockObject */
    private $orderService;

    protected function setUp(): void
    {
        $this->orderService = $this->getMockForAbstractClass(
            Order::class,
            [],
            '',
            false // don't call constructor
        );
    }

    private function productLine(string $gross, string $tax): array
    {
        return [
            'order_item_id' => '1',
            'gross_amount' => $gross,
            'tax_amount' => $tax,
        ];
    }

    // ── (a) no untracked total → no synthetic line ─────────────────────

    public function testOrdinaryOrderProducesNoSyntheticLine(): void
    {
        $lineItems = [
            $this->productLine('100.00', '20.00'),
            $this->productLine('50.00', '10.00'),
        ];

        // grand_total exactly matches sum(line_items.gross_amount)
        $result = $this->orderService->getOtherChargesLineItem($lineItems, 150.00, 30.00);

        $this->assertNull($result);
    }

    public function testSubCentRoundingNoiseDoesNotFalsePositive(): void
    {
        $lineItems = [
            $this->productLine('100.00', '20.00'),
        ];

        // 0.004 residual is float/rounding noise, not an untracked total.
        $result = $this->orderService->getOtherChargesLineItem($lineItems, 100.004, 20.00);

        $this->assertNull($result);
    }

    public function testNoLineItemsAndZeroGrandTotalProducesNoSyntheticLine(): void
    {
        $result = $this->orderService->getOtherChargesLineItem([], 0.0, 0.0);

        $this->assertNull($result);
    }

    // ── (b) an untracked total-collector amount is reconciled ──────────

    public function testUntrackedFeeProducesExactlyOneCorrectlyComputedLine(): void
    {
        $lineItems = [
            $this->productLine('100.00', '20.00'), // e.g. product incl. VAT
        ];

        // Simulated third-party fee (e.g. Amasty Extra Fee): net 10.00 +
        // tax 2.00 = gross 12.00, folded into grand_total/tax_amount by
        // the extension's totals collector but absent from line_items.
        $grandTotal = 100.00 + 12.00; // 112.00
        $taxTotal = 20.00 + 2.00;     // 22.00

        $result = $this->orderService->getOtherChargesLineItem($lineItems, $grandTotal, $taxTotal);

        $this->assertNotNull($result);
        $this->assertSame('other_charges', $result['order_item_id']);
        $this->assertSame('OTHER', $result['type']);
        $this->assertSame('12.00', $result['gross_amount']);
        $this->assertSame('2.00', $result['tax_amount']);
        $this->assertSame('10.00', $result['net_amount']);
        $this->assertSame('0.200000', $result['tax_rate']);
        $this->assertSame('1', (string)$result['quantity']);
    }

    public function testUntrackedFeeWithNoTaxComponent(): void
    {
        $lineItems = [
            $this->productLine('100.00', '0.00'),
        ];

        // Simulated tax-exempt untracked fee: gross == net, no tax.
        $result = $this->orderService->getOtherChargesLineItem($lineItems, 108.50, 0.00);

        $this->assertNotNull($result);
        $this->assertSame('8.50', $result['gross_amount']);
        $this->assertSame('0.00', $result['tax_amount']);
        $this->assertSame('8.50', $result['net_amount']);
        $this->assertSame('0.000000', $result['tax_rate']);
    }

    public function testNegativeResidualIsAlsoReconciled(): void
    {
        // Defensive: if known items somehow overshoot grand_total, the
        // residual is negative and still gets reconciled rather than
        // silently dropped or clamped.
        $lineItems = [
            $this->productLine('100.00', '20.00'),
        ];

        $result = $this->orderService->getOtherChargesLineItem($lineItems, 95.00, 20.00);

        $this->assertNotNull($result);
        $this->assertSame('-5.00', $result['gross_amount']);
    }
}
