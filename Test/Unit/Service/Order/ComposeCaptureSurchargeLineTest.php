<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Service\Order;

use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Invoice;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\Log\RepositoryInterface as LogRepository;
use Two\Gateway\Service\Order\ComposeCapture;

/**
 * Pre-existing gap this PR closes: Model\Total\Invoice\Surcharge::collect()
 * pours the remaining surcharge net into invoice.grand_total and its VAT
 * into invoice.tax_amount (via Magento's native tax collector), but
 * ComposeCapture::getLineItemsInvoice() never turned that into a line item —
 * there was no 'surcharge' entry alongside 'shipping'. Without this line,
 * the new Order::getOtherChargesLineItem() fallback would mistake Two's own
 * known BUYER_FEE for an unrecognized third-party fee on every capture of a
 * surcharge-bearing order (caught in review — see PR discussion).
 *
 * Uses the real faithful DataObject-semantics stubs from
 * Test/Stubs/SalesModels.php (magic get/set matching Magento's), not mocks,
 * so this exercises getLineItemsInvoice()'s real code path rather than a
 * pre-canned expectation.
 */
class ComposeCaptureSurchargeLineTest extends TestCase
{
    /** @var ComposeCapture|\PHPUnit\Framework\MockObject\MockObject */
    private $composeCapture;

    protected function setUp(): void
    {
        // ComposeCapture is concrete (not abstract), so build it via
        // getMockBuilder() with the constructor disabled and no methods
        // replaced — every method under test runs its real implementation.
        $this->composeCapture = $this->getMockBuilder(ComposeCapture::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getMock();

        // Constructor skipped — inject the logger directly, same pattern as
        // NegativeDiscountGuardTest / OtherChargesLineItemTest.
        $property = new \ReflectionProperty(\Two\Gateway\Service\Order::class, 'logRepository');
        $property->setValue($this->composeCapture, $this->createMock(LogRepository::class));
    }

    private function makeInvoiceAndOrder(): array
    {
        $order = new Order();
        $order->setAllItems([]);
        $order->setShippingAmount(0.0);

        $invoice = new Invoice();
        $invoice->setOrder($order);
        $invoice->setAllItems([]);

        return [$invoice, $order];
    }

    public function testNoSurchargeProducesNoSurchargeLine(): void
    {
        [$invoice, $order] = $this->makeInvoiceAndOrder();
        // getTwoSurchargeAmount() unset -> null, guard is `> 0`.

        $items = $this->composeCapture->getLineItemsInvoice($invoice, $order);

        $this->assertSame([], $items);
    }

    public function testSurchargeRemainingOnInvoiceProducesABuyerFeeLine(): void
    {
        [$invoice, $order] = $this->makeInvoiceAndOrder();

        // Mirrors what Model\Total\Invoice\Surcharge::collect() sets on the
        // invoice before ComposeCapture ever runs.
        $invoice->setTwoSurchargeAmount(10.00);
        $invoice->setTwoSurchargeTaxAmount(2.00);
        $invoice->setTwoSurchargeDescription('Payment terms fee - 30 days');
        $invoice->setTwoSurchargeTaxRate(20.0);

        $items = $this->composeCapture->getLineItemsInvoice($invoice, $order);

        $this->assertCount(1, $items);
        $line = $items[0];
        $this->assertSame('surcharge', $line['order_item_id']);
        $this->assertSame('BUYER_FEE', $line['type']);
        $this->assertSame('12.00', $line['gross_amount']);
        $this->assertSame('10.00', $line['net_amount']);
        $this->assertSame('2.00', $line['tax_amount']);
        $this->assertSame('0.200000', $line['tax_rate']);
        $this->assertSame('Payment terms fee - 30 days', $line['name']);
    }

    /**
     * The regression this PR would otherwise ship: without the surcharge
     * line above, invoice.grand_total/tax_amount carry the surcharge but
     * line_items doesn't, so getOtherChargesLineItem() treats a routine
     * surcharge capture as an unrecognized taxed residual and logs an
     * error on every single one. With the line present, the residual is
     * zero and nothing is logged.
     */
    public function testSurchargeLineMeansNoResidualIsLeftForTheFallback(): void
    {
        [$invoice, $order] = $this->makeInvoiceAndOrder();
        $invoice->setTwoSurchargeAmount(10.00);
        $invoice->setTwoSurchargeTaxAmount(2.00);
        $invoice->setTwoSurchargeTaxRate(20.0);

        $items = $this->composeCapture->getLineItemsInvoice($invoice, $order);

        // invoice.grand_total = 12.00 (surcharge only, in this minimal
        // fixture), invoice.tax_amount = 2.00 (surcharge VAT) — exactly
        // what the real collector leaves behind for a surcharge-only
        // invoice with no product/shipping lines.
        $result = $this->composeCapture->getOtherChargesLineItem($items, 12.00, 2.00);

        $this->assertNull($result);
    }
}
