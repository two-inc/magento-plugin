<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Service\Order;

use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\Config\RepositoryInterface as ConfigRepository;
use Two\Gateway\Service\Order;

/**
 * TWO-25502: the "Validate tax subtotals" setting gates the tax_subtotals
 * block that ComposeOrder/ComposeShipment/ComposeCapture/ComposeRefund put
 * in the Two API payload. Two validates gross_amount against the sum of
 * these subtotals, so per-rate grouping and the 6-dp rate precision are a
 * wire contract, not a presentation choice.
 */
class TaxSubtotalsTest extends TestCase
{
    private function orderService(bool $enabled): Order
    {
        $configRepository = $this->createMock(ConfigRepository::class);
        $configRepository->method('isTaxSubtotalsEnabled')->willReturn($enabled);

        $order = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->getMockForAbstractClass();
        $order->configRepository = $configRepository;

        return $order;
    }

    private function line(string $taxRate, string $net, string $tax): array
    {
        return ['tax_rate' => $taxRate, 'net_amount' => $net, 'tax_amount' => $tax];
    }

    public function testDisabledSettingOmitsTheBlockEntirely(): void
    {
        // Given the setting off; When composing; Then null, so the payload
        // builders omit the key rather than sending an empty list.
        $this->assertNull(
            $this->orderService(false)->getTaxSubtotals([$this->line('0.250000', '100.00', '25.00')])
        );
    }

    public function testLinesSharingARateAggregateIntoOneSubtotal(): void
    {
        // Given product, shipping and fee lines at one rate plus a second
        // rate; When composing; Then one entry per distinct rate.
        $result = $this->orderService(true)->getTaxSubtotals([
            $this->line('0.250000', '100.00', '25.00'),
            $this->line('0.250000', '50.00', '12.50'),
            $this->line('0.120000', '200.00', '24.00'),
        ]);

        $this->assertSame(
            [
                ['taxable_amount' => '150.00', 'tax_amount' => '37.50', 'tax_rate' => '0.250000'],
                ['taxable_amount' => '200.00', 'tax_amount' => '24.00', 'tax_rate' => '0.120000'],
            ],
            $result
        );
    }

    /**
     * A zero-rated line (exempt product, untaxed residual) must still appear:
     * Two reconciles gross_amount against every subtotal, so dropping it
     * fails validation on the whole order.
     */
    public function testZeroRatedLinesStillProduceASubtotal(): void
    {
        $result = $this->orderService(true)->getTaxSubtotals([
            $this->line('0.000000', '80.00', '0.00'),
        ]);

        $this->assertSame(
            [['taxable_amount' => '80.00', 'tax_amount' => '0.00', 'tax_rate' => '0.000000']],
            $result
        );
    }

    public function testNoLinesYieldNoSubtotals(): void
    {
        $this->assertSame([], $this->orderService(true)->getTaxSubtotals([]));
    }
}
