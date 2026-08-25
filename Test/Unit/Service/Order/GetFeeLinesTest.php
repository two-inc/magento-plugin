<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Service\Order;

use PHPUnit\Framework\TestCase;
use Two\Gateway\Service\Fee\FeeLineProviderPool;
use Two\Gateway\Service\Order;

/**
 * Order::getFeeLines() is a thin passthrough to the injected
 * FeeLineProviderPool. This test injects a pool via reflection rather than
 * the constructor, since Order is abstract and this double skips the
 * constructor entirely (see setUp()).
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
        $this->injectPool(new FeeLineProviderPool([]));

        $this->assertSame([], $this->orderService->getFeeLines(new \stdClass()));
    }
}
