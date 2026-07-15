<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Service\Order;

use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\Log\RepositoryInterface as LogRepository;
use Two\Gateway\Service\Order;

/**
 * TWO-25099 — fail loud on negative discount amounts before reaching the
 * Two API (Magento mirror of the PrestaShop guard shipped in TWO-24741).
 *
 * Covers:
 *  (a) legitimate positive discounts pass through untouched, at native
 *      float precision (no early rounding of the return value);
 *  (b) genuinely negative discounts log + throw (never silently clamp);
 *  (c) the rounding-order regression — sub-cent float residue that is
 *      negative at native precision but zero at the 2dp payload boundary
 *      must NOT false-positive the guard.
 */
class NegativeDiscountGuardTest extends TestCase
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

    /**
     * Item stub with the getters getDiscountAmountItem() consumes.
     */
    private function makeItem(float $discount, float $taxCompensation): object
    {
        return new class($discount, $taxCompensation) {
            private $discount;
            private $taxCompensation;

            public function __construct(float $discount, float $taxCompensation)
            {
                $this->discount = $discount;
                $this->taxCompensation = $taxCompensation;
            }

            public function getDiscountAmount(): float
            {
                return $this->discount;
            }

            public function getDiscountTaxCompensationAmount(): float
            {
                return $this->taxCompensation;
            }

            public function getId(): string
            {
                return '42';
            }

            public function getSku(): string
            {
                return 'TEST-SKU';
            }
        };
    }

    /**
     * Order/creditmemo stub with the getters getDiscountAmountShipping() consumes.
     */
    private function makeEntity(float $shippingDiscount, float $taxCompensation): object
    {
        return new class($shippingDiscount, $taxCompensation) {
            private $shippingDiscount;
            private $taxCompensation;

            public function __construct(float $shippingDiscount, float $taxCompensation)
            {
                $this->shippingDiscount = $shippingDiscount;
                $this->taxCompensation = $taxCompensation;
            }

            public function getShippingDiscountAmount(): float
            {
                return $this->shippingDiscount;
            }

            public function getShippingDiscountTaxCompensationAmount(): float
            {
                return $this->taxCompensation;
            }

            public function getIncrementId(): string
            {
                return '100000042';
            }
        };
    }

    // ── (a) legitimate discounts pass through untouched ────────────────

    public function testPositiveDiscountPassesThroughAtNativePrecision(): void
    {
        $this->logRepository->expects($this->never())->method('addErrorLog');

        $item = $this->makeItem(10.50, 2.10);

        $this->assertSame(10.50 - 2.10, $this->orderService->getDiscountAmountItem($item));
    }

    public function testPositiveDiscountReturnValueIsNotRoundedEarly(): void
    {
        // The guard must not round the value it returns — the single round
        // belongs at the payload boundary (roundAmt).
        $item = $this->makeItem(2.345678, 0.0);

        $this->assertSame(2.345678, $this->orderService->getDiscountAmountItem($item));
    }

    public function testZeroDiscountPassesThrough(): void
    {
        $this->logRepository->expects($this->never())->method('addErrorLog');

        $item = $this->makeItem(0.0, 0.0);

        $this->assertSame(0.0, $this->orderService->getDiscountAmountItem($item));
    }

    public function testPositiveShippingDiscountPassesThrough(): void
    {
        $this->logRepository->expects($this->never())->method('addErrorLog');

        $entity = $this->makeEntity(5.00, 1.00);

        $this->assertSame(5.00 - 1.00, $this->orderService->getDiscountAmountShipping($entity));
    }

    // ── (b) genuinely negative discounts fail loud ─────────────────────

    public function testNegativeItemDiscountLogsAndThrows(): void
    {
        $this->logRepository->expects($this->once())
            ->method('addErrorLog')
            ->with(
                'NegativeDiscountGuard',
                $this->logicalAnd(
                    $this->stringContains('TEST-SKU'),
                    $this->stringContains('Negative discount amount')
                )
            );

        $item = $this->makeItem(0.00, 5.00); // native -5.00

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Negative discount amount');

        $this->orderService->getDiscountAmountItem($item);
    }

    public function testNegativeShippingDiscountLogsAndThrows(): void
    {
        $this->logRepository->expects($this->once())
            ->method('addErrorLog')
            ->with(
                'NegativeDiscountGuard',
                $this->logicalAnd(
                    $this->stringContains('100000042'),
                    $this->stringContains('Negative shipping discount amount')
                )
            );

        $entity = $this->makeEntity(-3.25, 0.0);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Negative shipping discount amount');

        $this->orderService->getDiscountAmountShipping($entity);
    }

    public function testHalfCentNegativeDiscountThrows(): void
    {
        // -0.005 rounds to -0.01 at the payload boundary — a payload the
        // checkout-api would reject, so the guard must fire.
        $item = $this->makeItem(0.000, 0.005);

        $this->expectException(LocalizedException::class);

        $this->orderService->getDiscountAmountItem($item);
    }

    // ── (c) rounding-order regression: float residue must not throw ────

    public function testSubCentFloatResidueDoesNotFalsePositive(): void
    {
        // Classic binary-float residue: 0.3 - (0.1 + 0.2) is a tiny
        // NEGATIVE number at native precision. A naive `< 0` sign check on
        // the raw float — or any early per-component rounding scheme —
        // would fail loud on a perfectly legitimate cart. The guard must
        // evaluate the sign at the 2dp payload precision instead.
        $discount = 0.3;
        $compensation = 0.1 + 0.2; // 0.30000000000000004

        // Premise guard: this case genuinely exercises the residue path.
        $this->assertLessThan(0, $discount - $compensation);

        $this->logRepository->expects($this->never())->method('addErrorLog');

        $item = $this->makeItem($discount, $compensation);
        $result = $this->orderService->getDiscountAmountItem($item);

        // Value flows through natively; the payload boundary rounds it to 0.00.
        $this->assertSame($discount - $compensation, $result);
        $this->assertSame(0.0, round($result, 2) + 0.0);
    }

    public function testSubCentNegativeBelowHalfCentDoesNotThrow(): void
    {
        // -0.004 is zero at currency precision — not a data error.
        $item = $this->makeItem(0.001, 0.005);

        $this->logRepository->expects($this->never())->method('addErrorLog');

        $this->assertSame(0.001 - 0.005, $this->orderService->getDiscountAmountItem($item));
    }

    public function testSubCentFloatResidueShippingDoesNotFalsePositive(): void
    {
        $discount = 0.3;
        $compensation = 0.1 + 0.2;

        $this->assertLessThan(0, $discount - $compensation);
        $this->logRepository->expects($this->never())->method('addErrorLog');

        $entity = $this->makeEntity($discount, $compensation);

        $this->assertSame($discount - $compensation, $this->orderService->getDiscountAmountShipping($entity));
    }
}
