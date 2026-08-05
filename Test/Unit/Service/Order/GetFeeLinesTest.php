<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Service\Order;

use PHPUnit\Framework\TestCase;
use Two\Gateway\Service\Fee\FeeLineProviderPool;
use Two\Gateway\Service\Order;

/**
 * Order::getFeeLines() is a thin passthrough to the injected
 * FeeLineProviderPool. The null-coalescing "no pool means an empty one"
 * default lives ONLY in __construct() — this test injects a real (empty)
 * pool via reflection to exercise the same real-world shape a caller going
 * through the constructor gets, rather than duplicating the default in the
 * getter itself.
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

    private function injectPool($pool): void
    {
        $property = new \ReflectionProperty(Order::class, 'feeLineProviderPool');
        $property->setValue($this->orderService, $pool);
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

        $this->injectPool($pool);

        $this->assertSame($expected, $this->orderService->getFeeLines($entity));
    }

    public function testEmptyPoolReturnsNoLines(): void
    {
        // Mirrors the constructor's default when nothing is registered in
        // etc/di.xml (the current, zero-provider state).
        $this->injectPool(new FeeLineProviderPool([]));

        $this->assertSame([], $this->orderService->getFeeLines(new \stdClass()));
    }
}
