<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Service\Order;

use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Invoice;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\Config\RepositoryInterface as ConfigRepository;
use Two\Gateway\Api\Log\RepositoryInterface as LogRepository;
use Two\Gateway\Service\Fee\FeeLineProviderPool;
use Two\Gateway\Service\Order\ComposeCapture;

/**
 * End-to-end coverage for ComposeCapture::execute() -> reconcileOtherCharges()
 * -> getOtherChargesLineItem(), the actual call path a real capture takes.
 * Earlier tests only exercised getLineItemsInvoice() and a manual
 * getOtherChargesLineItem() call with hand-picked totals (found as a gap in
 * adversarial review) — this drives the real execute() method so the
 * merge-then-reconcile ordering inside reconcileOtherCharges() is actually
 * exercised, not just its constituent pure functions.
 *
 * Uses new Order()/new Invoice() — the real Magento sales models, faithful
 * DataObject semantics via magic get/set — the same pattern used across
 * this repo's other Compose and Model\Total tests.
 */
class ComposeCaptureExecuteReconciliationTest extends TestCase
{
    /** @var ComposeCapture|\PHPUnit\Framework\MockObject\MockObject */
    private $composeCapture;

    protected function setUp(): void
    {
        $this->composeCapture = $this->getMockBuilder(ComposeCapture::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getMock();

        // Constructor skipped — inject the collaborators execute() needs
        // directly, same pattern as ComposeCaptureSurchargeLineTest.
        $property = new \ReflectionProperty(\Two\Gateway\Service\Order::class, 'logRepository');
        $property->setValue($this->composeCapture, $this->createMock(LogRepository::class));

        // Constructor skipped, so the pool the real constructor would
        // default to is never set — inject the same empty pool by hand
        // (mirrors the current zero-provider etc/di.xml state).
        $poolProperty = new \ReflectionProperty(\Two\Gateway\Service\Order::class, 'feeLineProviderPool');
        $poolProperty->setValue($this->composeCapture, new FeeLineProviderPool([]));

        $configRepository = $this->createMock(ConfigRepository::class);
        $configRepository->method('isTaxSubtotalsEnabled')->willReturn(false);
        $this->composeCapture->configRepository = $configRepository;
    }

    private function makeInvoiceAndOrder(): array
    {
        $order = new Order();
        $order->setAllItems([]);
        $order->setShippingAmount(0.0);

        $invoice = new Invoice();
        $invoice->setOrder($order);
        $invoice->setAllItems([]);
        $invoice->setDiscountAmount(0.0);

        return [$invoice, $order];
    }

    public function testOrdinaryCaptureWithNoUntrackedFeeHasNoSyntheticLine(): void
    {
        [$invoice] = $this->makeInvoiceAndOrder();
        $invoice->setGrandTotal(0.0);
        $invoice->setTaxAmount(0.0);

        $reqBody = $this->composeCapture->execute($invoice);

        $this->assertSame([], $reqBody['line_items']);
        $this->assertSame('0.00', $reqBody['gross_amount']);
    }

    public function testUntrackedUntaxedFeeOnCaptureIsReconciledThroughExecute(): void
    {
        [$invoice] = $this->makeInvoiceAndOrder();

        // No products, no shipping, no surcharge — the invoice's own
        // grand_total is entirely an untracked, untaxed fee (e.g. a
        // third-party totals-collector extension) that reconcileOtherCharges()
        // must catch via the getOtherChargesLineItem() fallback (zero
        // providers registered, so getFeeLines() contributes nothing).
        $invoice->setGrandTotal(12.00);
        $invoice->setTaxAmount(0.0);

        $reqBody = $this->composeCapture->execute($invoice);

        $this->assertCount(1, $reqBody['line_items']);
        $line = $reqBody['line_items'][0];
        $this->assertSame('other_charges', $line['order_item_id']);
        $this->assertSame('OTHER', $line['type']);
        $this->assertSame('12.00', $line['gross_amount']);
        $this->assertSame('12.00', $reqBody['gross_amount']);
    }
}
