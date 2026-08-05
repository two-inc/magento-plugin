<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Service\Fee;

use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\Fee\FeeLineProviderInterface;
use Two\Gateway\Api\Log\RepositoryInterface as LogRepository;
use Two\Gateway\Service\Fee\FeeLineProviderPool;

/**
 * FeeLineProviderPool aggregates zero or more FeeLineProviderInterface
 * implementations, none of which are registered by default (see
 * etc/di.xml) — building one requires a specific extension's real
 * field/table names, verified against an actual install.
 *
 * Also covers the pool's isolation of a misbehaving provider: a provider
 * that throws, or returns a line missing/non-numeric gross_amount or
 * tax_amount (the two fields getOtherChargesLineItem() sums), must not
 * take down checkout/capture/refund for every other order, and must not
 * silently corrupt the residual computation via a cast-to-0.
 */
class FeeLineProviderPoolTest extends TestCase
{
    private function feeLine(string $orderItemId, string $gross = '10.00', string $tax = '2.00'): array
    {
        return [
            'order_item_id' => $orderItemId,
            'gross_amount' => $gross,
            'tax_amount' => $tax,
        ];
    }

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
            ->willReturn([$this->feeLine('fee_a')]);

        $providerB = $this->createMock(FeeLineProviderInterface::class);
        $providerB->expects($this->once())
            ->method('getFeeLines')
            ->with($entity)
            ->willReturn([$this->feeLine('fee_b_1'), $this->feeLine('fee_b_2')]);

        $pool = new FeeLineProviderPool([$providerA, $providerB]);

        $result = $pool->getFeeLines($entity);

        $this->assertSame(
            [
                $this->feeLine('fee_a'),
                $this->feeLine('fee_b_1'),
                $this->feeLine('fee_b_2'),
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

    // ── isolation: a throwing provider doesn't take down the others ────

    public function testThrowingProviderIsIsolatedAndLogged(): void
    {
        $entity = new \stdClass();

        $throwing = $this->createMock(FeeLineProviderInterface::class);
        $throwing->method('getFeeLines')->willThrowException(new \RuntimeException('boom'));

        $healthy = $this->createMock(FeeLineProviderInterface::class);
        $healthy->method('getFeeLines')->willReturn([$this->feeLine('fee_ok')]);

        $logRepository = $this->createMock(LogRepository::class);
        $logRepository->expects($this->once())
            ->method('addErrorLog')
            ->with('FeeLineProviderThrew', $this->isType('string'));

        $pool = new FeeLineProviderPool([$throwing, $healthy], $logRepository);

        $result = $pool->getFeeLines($entity);

        $this->assertSame([$this->feeLine('fee_ok')], $result);
    }

    public function testThrowingProviderDoesNotLogWhenNoLogRepositoryInjected(): void
    {
        // Defensive fallback (e.g. the empty-pool default constructed
        // inline in Order.php) — must not fatal on a null logger.
        $throwing = $this->createMock(FeeLineProviderInterface::class);
        $throwing->method('getFeeLines')->willThrowException(new \RuntimeException('boom'));

        $pool = new FeeLineProviderPool([$throwing]);

        $this->assertSame([], $pool->getFeeLines(new \stdClass()));
    }

    // ── validation: a malformed line is dropped, not silently miscounted ──

    public function testLineMissingGrossAmountIsDroppedAndLogged(): void
    {
        $provider = $this->createMock(FeeLineProviderInterface::class);
        $provider->method('getFeeLines')->willReturn([
            ['order_item_id' => 'malformed', 'tax_amount' => '2.00'], // no gross_amount
            $this->feeLine('fee_ok'),
        ]);

        $logRepository = $this->createMock(LogRepository::class);
        $logRepository->expects($this->once())
            ->method('addErrorLog')
            ->with('FeeLineProviderMalformedLine', $this->isType('string'));

        $pool = new FeeLineProviderPool([$provider], $logRepository);

        $result = $pool->getFeeLines(new \stdClass());

        $this->assertSame([$this->feeLine('fee_ok')], $result);
    }

    public function testLineWithNonNumericTaxAmountIsDropped(): void
    {
        $provider = $this->createMock(FeeLineProviderInterface::class);
        $provider->method('getFeeLines')->willReturn([
            ['order_item_id' => 'malformed', 'gross_amount' => '12.00', 'tax_amount' => 'oops'],
        ]);

        $pool = new FeeLineProviderPool([$provider], $this->createMock(LogRepository::class));

        $this->assertSame([], $pool->getFeeLines(new \stdClass()));
    }
}
