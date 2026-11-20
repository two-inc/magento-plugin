<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Cron;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Sales\Model\Order;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\BrandOverlayRegistryInterface;
use Two\Gateway\Api\Log\RepositoryInterface as LogRepository;
use Two\Gateway\Cron\CheckPendingOrders;
use Two\Gateway\Model\Two;
use Two\Gateway\Service\Payment\PendingOrderResolution;
use Two\Gateway\Service\Payment\PendingOrderResolver;

/**
 * TWO-26300: the scheduled check picks waiting Two orders between the
 * threshold and 7 days old, not checked in the last 15 minutes, oldest check
 * first, 50 per run, and settles each through the resolver.
 */
class CheckPendingOrdersTest extends TestCase
{
    private const NOW = 1_800_000_000;

    /** @var array[] what the collection was asked */
    private $query = [];

    /** @var Order[] */
    private $items = [];

    /** @var array[] */
    private $debugLogs = [];

    public static function thresholdCases(): array
    {
        return [
            'default threshold of 30 minutes' => [null, 30],
            'the hidden setting shortens it' => ['2', 2],
            'a nonsense setting falls back to 30 minutes' => ['0', 30],
        ];
    }

    #[DataProvider('thresholdCases')]
    public function testTheSelection(?string $setting, int $expectedMinutes): void
    {
        $this->cron($setting, $this->createMock(PendingOrderResolver::class))->selectOrders(self::NOW);

        $utc = static fn (int $seconds): string => gmdate('Y-m-d H:i:s', self::NOW - $seconds);
        $description = $this->dataName();
        $this->assertSame([
            ['filter', 'state', Order::STATE_PENDING_PAYMENT],
            ['filter', 'status', Two::STATUS_PENDING],
            ['filter', 'two_order_id', ['notnull' => true]],
            ['filter', 'created_at', ['lteq' => $utc($expectedMinutes * 60)]],
            ['filter', 'created_at', ['gteq' => $utc(7 * 86400)]],
            ['filter', 'two_pending_checked_at', [['null' => true], ['lt' => $utc(15 * 60)]]],
            ['order', 'two_pending_checked_at', 'ASC'],
            ['pageSize', 50],
            ['curPage', 1],
        ], $this->query, "$description: query");
    }

    public function testEachOrderIsSettledAndOneFailureDoesNotEndTheRun(): void
    {
        $orders = [];
        foreach ([1 => 'two_payment', 2 => 'checkmo', 3 => 'two_payment', 4 => 'two_payment'] as $id => $method) {
            $orders[$id] = (new Order())->setId($id)->setIncrementId("10000000$id")
                ->setPayment((new Order\Payment())->setMethod($method));
        }
        $this->items = $orders;
        $resolved = [];
        $resolver = $this->createMock(PendingOrderResolver::class);
        $resolver->method('resolve')->willReturnCallback(function ($order, $trigger) use (&$resolved) {
            $resolved[] = [(int)$order->getId(), $trigger];
            if ((int)$order->getId() === 3) {
                throw new \RuntimeException('lock backend down');
            }
            return new PendingOrderResolution(PendingOrderResolver::OUTCOME_CONFIRMED, $order);
        });

        $this->cron(null, $resolver)->execute();

        $this->assertSame([
            [1, PendingOrderResolver::TRIGGER_SCHEDULED],
            [3, PendingOrderResolver::TRIGGER_SCHEDULED],
            [4, PendingOrderResolver::TRIGGER_SCHEDULED],
        ], $resolved, 'only Two orders are checked, and the run carries on past a failure');
        $this->assertSame(
            [['pending-order-check-run', ['checked' => 3, 'confirmed' => 2, 'failed' => 1]]],
            $this->debugLogs,
            'run summary'
        );
    }

    private function cron(?string $setting, PendingOrderResolver $resolver): CheckPendingOrders
    {
        $test = $this;
        $collection = new class ($test) {
            private $test;

            public function __construct($test)
            {
                $this->test = $test;
            }

            public function __call($method, $args)
            {
                return $this->test->ask($this, $method, $args);
            }
        };
        $factory = new class ($collection) {
            private $collection;

            public function __construct($collection)
            {
                $this->collection = $collection;
            }

            public function create()
            {
                return $this->collection;
            }
        };
        $config = $this->createMock(ScopeConfigInterface::class);
        $config->method('getValue')->willReturnCallback(
            static fn ($path) => $path === CheckPendingOrders::XML_PATH_CHECK_AFTER_MINUTES ? $setting : null
        );
        $overlay = $this->createMock(BrandOverlayRegistryInterface::class);
        $overlay->method('isTwoStackMethod')->willReturnCallback(static fn ($code) => $code === 'two_payment');
        $log = $this->createMock(LogRepository::class);
        $log->method('addDebugLog')->willReturnCallback(function ($type, $data) {
            $this->debugLogs[] = [$type, $data];
        });

        $reflection = new \ReflectionClass(CheckPendingOrders::class);
        $cron = $reflection->newInstanceWithoutConstructor();
        $values = [
            'collectionFactory' => $factory,
            'resolver' => $resolver,
            'scopeConfig' => $config,
            'overlayRegistry' => $overlay,
            'logRepository' => $log,
        ];
        foreach ($values as $property => $value) {
            $reflection->getProperty($property)->setValue($cron, $value);
        }
        return $cron;
    }

    /**
     * Records what the fake order collection is asked.
     */
    public function ask(object $collection, string $method, array $args)
    {
        switch ($method) {
            case 'addFieldToFilter':
                $this->query[] = ['filter', $args[0], $args[1]];
                return $collection;
            case 'setOrder':
                $this->query[] = ['order', $args[0], $args[1]];
                return $collection;
            case 'setPageSize':
                $this->query[] = ['pageSize', $args[0]];
                return $collection;
            case 'setCurPage':
                $this->query[] = ['curPage', $args[0]];
                return $collection;
            case 'getItems':
                return $this->items;
        }
        throw new \LogicException("unexpected collection call $method");
    }
}
