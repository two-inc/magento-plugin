<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Service\Order;

use PHPUnit\Framework\TestCase;
use Two\Gateway\Service\Fee\FeeLineProviderPool;
use Two\Gateway\Service\Order;

/**
 * Order::getFeeLines() is a thin passthrough to the injected
 * FeeLineProviderPool — this pins the delegation and the
 * defaults-to-an-empty-pool behaviour when no pool is injected
 * (constructor skipped in tests, or a caller pre-dating this change).
 */
class GetFeeLinesTest extends TestCase
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

    public function testDelegatesToInjectedPool(): void
    {
        $entity = new \stdClass();
        $expected = [['order_item_id' => 'fee_a']];

        $pool = $this->createMock(FeeLineProviderPool::class);
        $pool->expects($this->once())
            ->method('getFeeLines')
            ->with($entity)
            ->willReturn($expected);

        $property = new \ReflectionProperty(Order::class, 'feeLineProviderPool');
        $property->setValue($this->orderService, $pool);

        $this->assertSame($expected, $this->orderService->getFeeLines($entity));
    }

    public function testNoPoolInjectedBehavesAsEmptyPool(): void
    {
        // Constructor is skipped by getMockForAbstractClass(..., false), so
        // feeLineProviderPool is never set — mirrors any caller that
        // doesn't go through the constructor's null-coalescing default.
        $this->assertSame([], $this->orderService->getFeeLines(new \stdClass()));
    }
}
