<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Service\Fee;

use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\Fee\FeeLineProviderInterface;
use Two\Gateway\Service\Fee\FeeLineProviderPool;

/**
 * FeeLineProviderPool aggregates zero or more FeeLineProviderInterface
 * implementations, none of which are registered by default (see
 * etc/di.xml) — building one requires a specific extension's real
 * field/table names, verified against an actual install.
 */
class FeeLineProviderPoolTest extends TestCase
{
    public function testEmptyPoolReturnsNoLines(): void
    {
        $pool = new FeeLineProviderPool([]);

        $this->assertSame([], $pool->getFeeLines(new \stdClass()));
    }

    public function testDefaultConstructorArgumentIsEmptyPool(): void
    {
        $pool = new FeeLineProviderPool();

        $this->assertSame([], $pool->getFeeLines(new \stdClass()));
    }

    public function testAggregatesLinesFromMultipleProviders(): void
    {
        $entity = new \stdClass();

        $providerA = $this->createMock(FeeLineProviderInterface::class);
        $providerA->expects($this->once())
            ->method('getFeeLines')
            ->with($entity)
            ->willReturn([['order_item_id' => 'fee_a']]);

        $providerB = $this->createMock(FeeLineProviderInterface::class);
        $providerB->expects($this->once())
            ->method('getFeeLines')
            ->with($entity)
            ->willReturn([['order_item_id' => 'fee_b_1'], ['order_item_id' => 'fee_b_2']]);

        $pool = new FeeLineProviderPool([$providerA, $providerB]);

        $result = $pool->getFeeLines($entity);

        $this->assertSame(
            [
                ['order_item_id' => 'fee_a'],
                ['order_item_id' => 'fee_b_1'],
                ['order_item_id' => 'fee_b_2'],
            ],
            $result
        );
    }

    public function testProviderReturningNoLinesContributesNothing(): void
    {
        $entity = new \stdClass();

        $provider = $this->createMock(FeeLineProviderInterface::class);
        $provider->method('getFeeLines')->willReturn([]);

        $pool = new FeeLineProviderPool([$provider]);

        $this->assertSame([], $pool->getFeeLines($entity));
    }
}
