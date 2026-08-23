<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Service\Order;

use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\Log\RepositoryInterface as LogRepository;
use Two\Gateway\Service\Order;

/**
 * TWO-25503: a line whose declared tax does not follow from its own declared
 * rate and net amount is internally inconsistent. The plugin never corrects
 * such numbers, so it declines the checkout with a generic notice and logs
 * the detail. Tolerance is 0.02 currency units, matching the sibling plugins.
 */
class TaxReconciliationTest extends TestCase
{
    /** @var LogRepository|\PHPUnit\Framework\MockObject\MockObject */
    private $logRepository;

    /** @var Order|\PHPUnit\Framework\MockObject\MockObject */
    private $orderService;

    protected function setUp(): void
    {
        $this->orderService = $this->getMockForAbstractClass(Order::class, [], '', false);
        $this->logRepository = $this->createMock(LogRepository::class);

        $property = new \ReflectionProperty(Order::class, 'logRepository');
        $property->setAccessible(true);
        $property->setValue($this->orderService, $this->logRepository);
    }

    private function line(string $id, string $net, string $tax, string $rate): array
    {
        return [
            'order_item_id' => $id,
            'net_amount' => $net,
            'tax_amount' => $tax,
            'tax_rate' => $rate,
        ];
    }

    /**
     * @dataProvider reconcilingLines
     */
    public function testAReconcilingLinePassesTheGate(array $line, string $case): void
    {
        $this->logRepository->expects($this->never())->method('addErrorLog');

        $thrown = null;
        try {
            $this->orderService->validateTaxReconciliation([$line]);
        } catch (LocalizedException $exception) {
            $thrown = $exception;
        }

        $this->assertNull($thrown, $case);
    }

    public function reconcilingLines(): array
    {
        return [
            [$this->line('1', '100.00', '25.00', '0.250000'), 'exact'],
            [$this->line('shipping', '0.00', '0.00', '0.000000'), 'untaxed zero-amount line'],
            [$this->line('2', '80.00', '0.00', '0.000000'), 'zero-rated line'],
            [$this->line('3', '100.00', '25.02', '0.250000'), 'at the tolerance boundary, above'],
            [$this->line('4', '100.00', '24.98', '0.250000'), 'at the tolerance boundary, below'],
            [$this->line('5', '33.33', '8.33', '0.250000'), '2dp rounding on both sides'],
        ];
    }

    /**
     * @dataProvider nonReconcilingLines
     */
    public function testALineOutsideToleranceDeclinesTheCheckout(array $line, string $case): void
    {
        $this->logRepository->expects($this->once())->method('addErrorLog');

        $thrown = null;
        try {
            $this->orderService->validateTaxReconciliation([$line]);
        } catch (LocalizedException $exception) {
            $thrown = $exception;
        }

        $this->assertNotNull($thrown, $case);
        $this->assertSame('This order could not be placed. Please contact the merchant.', $thrown->getMessage());
    }

    public function nonReconcilingLines(): array
    {
        return [
            [$this->line('1', '100.00', '25.03', '0.250000'), 'just outside tolerance'],
            [$this->line('2', '100.00', '25.00', '0.150000'), 'rate does not match the tax charged'],
            [$this->line('shipping', '100.00', '25.00', '0.000000'), 'taxed line declaring a zero rate'],
            [$this->line('3', '100.00', '0.00', '0.250000'), 'rate declared but no tax charged'],
        ];
    }

    /**
     * The buyer notice carries no line detail; the amounts go to the log so
     * the merchant can diagnose it.
     */
    public function testTheOffendingLineIsIdentifiedInTheLogNotToTheBuyer(): void
    {
        $this->logRepository->expects($this->once())
            ->method('addErrorLog')
            ->with(
                'TaxReconciliationFailed',
                $this->stringContains('Line shipping declares tax 25.00')
            );

        $this->expectException(LocalizedException::class);
        $this->orderService->validateTaxReconciliation([
            $this->line('1', '100.00', '25.00', '0.250000'),
            $this->line('shipping', '100.00', '25.00', '0.100000'),
        ]);
    }

    public function testNoLinesPassTrivially(): void
    {
        $this->logRepository->expects($this->never())->method('addErrorLog');

        $this->orderService->validateTaxReconciliation([]);
        $this->addToAssertionCount(1);
    }
}
