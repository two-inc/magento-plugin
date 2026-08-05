<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Service\Order;

use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\Log\RepositoryInterface as LogRepository;
use Two\Gateway\Service\Order;

/**
 * SECONDARY fallback reconciliation for any third-party totals-collector
 * amount (e.g. Amasty's "Extra Fee" module) that bumps grand_total the
 * same way Magento's own shipping total does, without being a
 * quote/order item — so it never appears in line_items even though it
 * is included in the aggregate total we report to Two.
 *
 * The PRIMARY mechanism is a registered FeeLineProviderInterface with
 * real per-fee knowledge (see FeeLineProviderPoolTest); this fallback
 * only ever sees what no provider recognized, so it does not know the
 * residual's real tax rate. It therefore only auto-emits a synthetic
 * line when the residual is genuinely untaxed (0% is always a valid,
 * honest statement); a residual with a non-zero tax component is
 * logged as a warning and left unreconciled rather than guessed at.
 *
 * Covers:
 *  (a) an ordinary order/invoice/creditmemo with no untracked total
 *      produces zero synthetic line items (no false positives from
 *      rounding noise);
 *  (b) an untaxed untracked total produces exactly one correctly
 *      computed synthetic line;
 *  (c) a TAXED untracked total is NOT auto-itemized — it's logged and
 *      left for a real FeeLineProviderInterface instead of guessed at.
 */
class OtherChargesLineItemTest extends TestCase
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

    // ── (a) no untracked total → no synthetic line ─────────────────────

    public function testOrdinaryOrderProducesNoSyntheticLine(): void
    {
        $this->logRepository->expects($this->never())->method('addErrorLog');

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
        $this->logRepository->expects($this->never())->method('addErrorLog');

        $lineItems = [
            $this->productLine('100.00', '20.00'),
        ];

        // 0.004 residual is float/rounding noise, not an untracked total.
        $result = $this->orderService->getOtherChargesLineItem($lineItems, 100.004, 20.00);

        $this->assertNull($result);
    }

    public function testCumulativeRoundingDriftAcrossManyLinesDoesNotFalsePositive(): void
    {
        // Each of the 10 lines is independently roundAmt()'d to 2dp, so the
        // entity's own higher-precision aggregate can legitimately drift up
        // to ~N*0.005 from sum(line_items) with ZERO third-party extension
        // involved (the same bound ComposeRefund's own comment documents).
        // A flat 1-cent epsilon would false-positive here; the scaled one
        // (max(0.01, 0.005*N) = 0.05 for N=10) must not.
        $this->logRepository->expects($this->never())->method('addErrorLog');

        $lineItems = array_fill(0, 10, $this->productLine('10.00', '2.00'));

        // sum(gross) = 100.00, sum(tax) = 20.00; drift of 0.04 is within the
        // scaled epsilon of 0.05 for 10 lines.
        $result = $this->orderService->getOtherChargesLineItem($lineItems, 100.04, 20.00);

        $this->assertNull($result);
    }

    public function testDriftBeyondTheScaledEpsilonStillFires(): void
    {
        // Same 10 lines, but the residual (0.06) exceeds the scaled
        // epsilon (0.05) — still a real untaxed residual to reconcile.
        $this->logRepository->expects($this->never())->method('addErrorLog');

        $lineItems = array_fill(0, 10, $this->productLine('10.00', '2.00'));

        $result = $this->orderService->getOtherChargesLineItem($lineItems, 100.06, 20.00);

        $this->assertNotNull($result);
        $this->assertSame('0.06', $result['gross_amount']);
    }

    public function testEpsilonIsCappedOnALargeOrderSoASmallFeeIsNotSilentlySwallowed(): void
    {
        // 500 lines: uncapped, 0.005*500 = 2.50 would swallow a 1.50
        // residual with zero log line at all (the quiet "rounding noise"
        // branch). The ceiling (1.00) must win, so this genuine untaxed
        // fee still gets reconciled.
        $this->logRepository->expects($this->never())->method('addErrorLog');

        $lineItems = array_fill(0, 500, $this->productLine('10.00', '2.00'));

        // sum(gross) = 5000.00; residual of 1.50 exceeds the 1.00 ceiling.
        $result = $this->orderService->getOtherChargesLineItem($lineItems, 5001.50, 1000.00);

        $this->assertNotNull($result);
        $this->assertSame('1.50', $result['gross_amount']);
    }

    public function testDriftUnderTheEpsilonCeilingOnALargeOrderStillDoesNotFalsePositive(): void
    {
        $this->logRepository->expects($this->never())->method('addErrorLog');

        $lineItems = array_fill(0, 500, $this->productLine('10.00', '2.00'));

        // A 0.90 residual is realistic rounding noise for 500 independently
        // rounded lines (well under the uncapped 2.50 bound) and still
        // under the 1.00 ceiling — must not fire.
        $result = $this->orderService->getOtherChargesLineItem($lineItems, 5000.90, 1000.00);

        $this->assertNull($result);
    }

    public function testNoLineItemsAndZeroGrandTotalProducesNoSyntheticLine(): void
    {
        $this->logRepository->expects($this->never())->method('addErrorLog');

        $result = $this->orderService->getOtherChargesLineItem([], 0.0, 0.0);

        $this->assertNull($result);
    }

    // ── (b) an untaxed untracked total is auto-reconciled ───────────────

    public function testUntaxedUntrackedFeeProducesExactlyOneCorrectlyComputedLine(): void
    {
        $this->logRepository->expects($this->never())->method('addErrorLog');

        $lineItems = [
            $this->productLine('100.00', '0.00'),
        ];

        // Simulated tax-exempt untracked fee: gross == net, no tax.
        $result = $this->orderService->getOtherChargesLineItem($lineItems, 108.50, 0.00);

        $this->assertNotNull($result);
        $this->assertSame('other_charges', $result['order_item_id']);
        $this->assertSame('OTHER', $result['type']);
        $this->assertSame('8.50', $result['gross_amount']);
        $this->assertSame('0.00', $result['tax_amount']);
        $this->assertSame('8.50', $result['net_amount']);
        $this->assertSame('0.000000', $result['tax_rate']);
        $this->assertSame('1', (string)$result['quantity']);
    }

    public function testNegativeResidualIsLoggedNotAutoReconciled(): void
    {
        // A negative residual (known items exceed grand_total) is NOT a
        // "fee we forgot" — it's more likely our own line-item math
        // double-counted something. Log it for diagnosis rather than
        // inventing a negative-amount correction line.
        $this->logRepository->expects($this->once())
            ->method('addErrorLog')
            ->with('UnreconciledOtherCharges', $this->isType('string'));

        $lineItems = [
            $this->productLine('100.00', '20.00'),
        ];

        $result = $this->orderService->getOtherChargesLineItem($lineItems, 95.00, 20.00);

        $this->assertNull($result);
    }

    // ── (c) a taxed untracked total is logged, NOT guessed ──────────────

    public function testTaxedUntrackedFeeIsNotAutoItemizedAndIsLogged(): void
    {
        $this->logRepository->expects($this->once())
            ->method('addErrorLog')
            ->with('UnreconciledOtherCharges', $this->isType('string'));

        $lineItems = [
            $this->productLine('100.00', '20.00'), // e.g. product incl. VAT
        ];

        // Simulated third-party fee (e.g. Amasty Extra Fee) WITH tax: net
        // 10.00 + tax 2.00 = gross 12.00, folded into grand_total/tax_amount
        // by the extension's totals collector but absent from line_items.
        // No provider recognizes it here, so its real tax rate is unknown
        // — must NOT be guessed.
        $grandTotal = 100.00 + 12.00; // 112.00
        $taxTotal = 20.00 + 2.00;     // 22.00

        $result = $this->orderService->getOtherChargesLineItem($lineItems, $grandTotal, $taxTotal);

        $this->assertNull($result);
    }

    public function testResidualTaxRoundingToZeroStillAutoEmits(): void
    {
        $this->logRepository->expects($this->never())->method('addErrorLog');

        $lineItems = [
            $this->productLine('100.00', '20.00'),
        ];

        // Residual tax of 0.004 rounds to 0.00 — still the safe, untaxed case.
        $result = $this->orderService->getOtherChargesLineItem($lineItems, 112.00, 20.004);

        $this->assertNotNull($result);
        $this->assertSame('0.00', $result['tax_amount']);
    }
}
