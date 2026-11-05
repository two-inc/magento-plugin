<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Service\Payment;

use Magento\Framework\DataObject;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Sales\Model\Order\Status\HistoryFactory;
use Magento\Sales\Model\Order;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\BrandRegistryInterface;
use Two\Gateway\Api\Log\RepositoryInterface as LogRepository;
use Two\Gateway\Model\Two;
use Two\Gateway\Service\Payment\OrderService;
use Two\Gateway\Service\Payment\PendingOrderResolver as R;

/**
 * TWO-26300: what a check of a waiting order does for each state Two reports,
 * for each of the three triggers that share the resolver.
 */
class PendingOrderResolverTest extends TestCase
{
    private const SCHEDULED_CONFIRMED = 'The buyer verified this order with Two but did not return to the shop. Confirmed with Two by the scheduled check.';
    private const ADMIN_CONFIRMED = 'The buyer verified this order with Two but did not return to the shop. Confirmed with Two by jane.';
    private const SCHEDULED_PROCESSED = 'The buyer verified this order with Two but did not return to the shop. Processed by the scheduled check: Two had already confirmed it.';
    private const ADMIN_PROCESSED = 'The buyer verified this order with Two but did not return to the shop. Processed by jane: Two had already confirmed it.';
    private const CANCELLED = 'Two reports this order as cancelled. Cancelled here to match.';
    private const DECLINED = 'Two declined this order.';
    private const UNVERIFIED = 'Checked with Two: the buyer has not finished verification.';
    private const REFUNDED = 'Two reports state REFUNDED; not changed. Check the order in the Two portal.';
    private const UNDEFINED = 'Two reports state undefined; not changed. Check the order in the Two portal.';
    private const BUYER_CANCELLED = 'Your invoice purchase with Two has been cancelled. The cart will be restored.';
    private const BUYER_NOT_PROCESSED = 'Your invoice purchase with Two could not be processed. The cart will be restored.';

    /** @var array[] [call, argument] in order */
    private $calls = [];

    public static function stateCases(): array
    {
        $rows = [];
        foreach ([R::TRIGGER_SCHEDULED => 'scheduled', R::TRIGGER_ADMIN => 'admin'] as $trigger => $who) {
            $admin = $trigger === R::TRIGGER_ADMIN;
            $confirmed = $admin ? self::ADMIN_CONFIRMED : self::SCHEDULED_CONFIRMED;
            $processed = $admin ? self::ADMIN_PROCESSED : self::SCHEDULED_PROCESSED;
            $rows["$who: VERIFIED is confirmed with Two and processed"] = [$trigger, ['state' => 'VERIFIED'], R::OUTCOME_CONFIRMED, [
                ['getTwoOrderFromApi', null], ['confirmOrder', null], ['addOrderComment', $confirmed], ['send', null], ['processOrder', 'two-1'],
            ]];
            foreach (['CONFIRMED', 'FULFILLING', 'FULFILLED', 'DELIVERED', 'FULFILMENT_NEEDS_MANUAL_RESOLUTION'] as $state) {
                $rows["$who: $state is processed without a confirm"] = [$trigger, ['state' => $state], R::OUTCOME_PROCESSED, [
                    ['getTwoOrderFromApi', null], ['addOrderComment', $processed], ['send', null], ['processOrder', 'two-1'],
                ]];
            }
            $rows["$who: CANCELLED is cancelled here only"] = [$trigger, ['state' => 'CANCELLED'], R::OUTCOME_CANCELLED, [
                ['getTwoOrderFromApi', null], ['cancelLocally', self::CANCELLED],
            ]];
            foreach (['REJECTED', 'DECLINED'] as $status) {
                $rows["$who: a $status status is cancelled here only"] = [$trigger, ['state' => 'UNVERIFIED', 'status' => $status], R::OUTCOME_DECLINED, [
                    ['getTwoOrderFromApi', null], ['cancelLocally', self::DECLINED],
                ]];
            }
            $rows["$who: UNVERIFIED changes nothing"] = [$trigger, ['state' => 'UNVERIFIED'], R::OUTCOME_UNVERIFIED, array_merge(
                [['getTwoOrderFromApi', null], ['stamp', null]],
                $admin ? [['comment', self::UNVERIFIED]] : []
            )];
            $rows["$who: a failed read changes nothing"] = [$trigger, new \Exception('connection reset'), R::OUTCOME_FAILED, array_merge(
                [['getTwoOrderFromApi', null], ['stamp', null]],
                $admin ? [['comment', 'Could not check the order with Two: connection reset']] : []
            )];
        }
        // Whichever trigger asks, a state this module does not act on changes
        // nothing but the check time, and is reported once per state.
        foreach ([R::TRIGGER_SCHEDULED => 'scheduled', R::TRIGGER_ADMIN => 'admin', R::TRIGGER_BUYER_RETURN => 'buyer return'] as $trigger => $who) {
            foreach (['REFUNDED' => self::REFUNDED, '' => self::UNDEFINED] as $state => $comment) {
                $label = $state !== '' ? $state : 'no state';
                $rows["$who: $label changes nothing and says so"] = [$trigger, $state !== '' ? ['state' => $state] : [], R::OUTCOME_UNCHANGED, [
                    ['getTwoOrderFromApi', null], ['stamp', null], ['comment', $comment],
                ]];
                $rows["$who: $label says so only once"] = [$trigger, $state !== '' ? ['state' => $state] : [], R::OUTCOME_UNCHANGED, [
                    ['getTwoOrderFromApi', null], ['stamp', null],
                ], [$comment]];
            }
        }
        $rows['scheduled: a refused confirm changes nothing'] = [R::TRIGGER_SCHEDULED, ['state' => 'VERIFIED'], R::OUTCOME_FAILED, [
            ['getTwoOrderFromApi', null], ['confirmOrder', null], ['stamp', null],
        ], [], true];
        $rows['buyer return: a refused confirm fails the return'] = [R::TRIGGER_BUYER_RETURN, ['state' => 'VERIFIED'], R::OUTCOME_FAILED, [
            ['getTwoOrderFromApi', null], ['confirmOrder', null], ['failBuyerReturn', 'refused'],
        ], [], true];
        $rows['buyer return: a failed read fails the return'] = [R::TRIGGER_BUYER_RETURN, new \Exception('connection reset'), R::OUTCOME_FAILED, [
            ['getTwoOrderFromApi', null], ['failBuyerReturn', 'connection reset'],
        ]];
        $rows['buyer return: VERIFIED is confirmed and processed without a recovery comment'] = [R::TRIGGER_BUYER_RETURN, ['state' => 'VERIFIED'], R::OUTCOME_CONFIRMED, [
            ['getTwoOrderFromApi', null], ['confirmOrder', null], ['send', null], ['processOrder', 'two-1'],
        ]];
        $rows['buyer return: CANCELLED fails the return while locked'] = [R::TRIGGER_BUYER_RETURN, ['state' => 'CANCELLED'], R::OUTCOME_CANCELLED, [
            ['getTwoOrderFromApi', null], ['addOrderComment', self::CANCELLED], ['failBuyerReturn', self::BUYER_CANCELLED],
        ]];
        $rows['buyer return: a REJECTED status fails the return while locked'] = [R::TRIGGER_BUYER_RETURN, ['state' => 'UNVERIFIED', 'status' => 'REJECTED'], R::OUTCOME_DECLINED, [
            ['getTwoOrderFromApi', null], ['addOrderComment', self::DECLINED], ['failBuyerReturn', self::BUYER_CANCELLED],
        ]];
        $rows['buyer return: UNVERIFIED fails the return while locked'] = [R::TRIGGER_BUYER_RETURN, ['state' => 'UNVERIFIED'], R::OUTCOME_UNVERIFIED, [
            ['getTwoOrderFromApi', null], ['addOrderComment', 'Unable to confirm Two order with UNVERIFIED state.'], ['failBuyerReturn', self::BUYER_NOT_PROCESSED],
        ]];
        return $rows;
    }

    #[DataProvider('stateCases')]
    public function testTheCheckActsOnTheStateTwoReports(
        string $trigger,
        $twoOrder,
        string $expectedOutcome,
        array $expectedCalls,
        array $earlierComments = [],
        bool $confirmRefused = false
    ): void {
        $order = $this->waitingOrder();
        $order->setStatusHistoryCollection(array_map(static fn ($c) => new DataObject(['comment' => $c]), $earlierComments));

        $resolution = $this->resolver($order, $twoOrder, $confirmRefused)->resolve($order, $trigger, 'jane');

        $description = $this->dataName();
        $this->assertSame($expectedOutcome, $resolution->getOutcome(), "$description: outcome");
        $this->assertSame($expectedCalls, $this->calls, "$description: actions");
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/',
            (string)$order->getData(OrderService::PENDING_CHECKED_AT),
            "$description: the check is recorded"
        );
    }

    public function testACancelDuringTheReadIsNotUndone(): void
    {
        foreach ([R::TRIGGER_SCHEDULED, R::TRIGGER_ADMIN, R::TRIGGER_BUYER_RETURN] as $trigger) {
            foreach (['CANCELLED', 'VERIFIED', 'REFUNDED'] as $state) {
                $this->calls = [];
                $waiting = $this->waitingOrder();
                $cancelled = $this->waitingOrder()->setState(Order::STATE_CANCELED)->setStatus('canceled');
                $resolution = $this->resolver($waiting, ['state' => $state], false, null, $cancelled)
                    ->resolve($waiting, $trigger);
                $description = "$trigger, Two says $state, the order was cancelled meanwhile";
                $this->assertSame(R::OUTCOME_NOT_WAITING, $resolution->getOutcome(), "$description: outcome");
                $this->assertSame([['getTwoOrderFromApi', null]], $this->calls, "$description: nothing written");
            }
        }
    }

    public static function lockCases(): array
    {
        return [
            'scheduled: never waits, and skips a held order' => [R::TRIGGER_SCHEDULED, 0],
            'admin: waits briefly, then reports it busy' => [R::TRIGGER_ADMIN, R::LOCK_WAIT_SECONDS],
            'buyer return: waits briefly, then reports it busy' => [R::TRIGGER_BUYER_RETURN, R::LOCK_WAIT_SECONDS],
        ];
    }

    #[DataProvider('lockCases')]
    public function testAHeldLockIsLeftAlone(string $trigger, int $expectedWait): void
    {
        $order = $this->waitingOrder();
        $waits = [];
        $resolver = $this->resolver($order, ['state' => 'VERIFIED'], false, function ($name, $wait) use (&$waits) {
            $waits[] = [$name, $wait];
            return false;
        });

        $resolution = $resolver->resolve($order, $trigger);

        $description = $this->dataName();
        $this->assertSame(R::OUTCOME_BUSY, $resolution->getOutcome(), "$description: outcome");
        $this->assertSame([[R::LOCK_PREFIX . '42', $expectedWait]], $waits, "$description: lock and wait");
        $this->assertSame([], $this->calls, "$description: nothing done");
    }

    public static function movedOnCases(): array
    {
        return [
            'processing by the time the lock is held' => [Order::STATE_PROCESSING, Order::STATE_PROCESSING, R::OUTCOME_ALREADY_PROCESSED],
            'failed by the time the lock is held' => [Order::STATE_CANCELED, Two::STATUS_FAILED, R::OUTCOME_NOT_WAITING],
        ];
    }

    #[DataProvider('movedOnCases')]
    public function testAnOrderThatMovedOnIsNotChecked(string $state, string $status, string $expectedOutcome): void
    {
        $stale = $this->waitingOrder();
        $fresh = $this->waitingOrder()->setState($state)->setStatus($status);

        foreach ([R::TRIGGER_SCHEDULED, R::TRIGGER_ADMIN, R::TRIGGER_BUYER_RETURN] as $trigger) {
            $this->calls = [];
            $resolution = $this->resolver($fresh, ['state' => 'VERIFIED'])->resolve($stale, $trigger);
            $description = $this->dataName() . " ($trigger)";
            $this->assertSame($expectedOutcome, $resolution->getOutcome(), "$description: outcome");
            $this->assertSame($fresh, $resolution->getOrder(), "$description: acts on the order read afresh");
            $this->assertSame([], $this->calls, "$description: nothing done");
        }
    }

    private function waitingOrder(): Order
    {
        return (new Order())->setState(Order::STATE_PENDING_PAYMENT)->setStatus(Two::STATUS_PENDING)
            ->setId(42)->setIncrementId('100000042')->setTwoOrderId('two-1')
            ->setBillingAddress(new DataObject());
    }

    /**
     * @param Order $fresh what the order reads as once the lock is held
     * @param array|\Exception $twoOrder the GET answer, or its failure
     */
    /**
     * @param Order $fresh what the order reads as once the lock is held
     * @param Order|null $afterRead what it reads as after Two was asked, when another request changed it
     */
    private function resolver(
        Order $fresh,
        $twoOrder,
        bool $confirmRefused = false,
        ?callable $lock = null,
        ?Order $afterRead = null
    ): R {
        $service = $this->getMockBuilder(OrderService::class)
            ->disableOriginalConstructor()
            ->onlyMethods([
                'reloadOrder', 'getTwoOrderFromApi', 'confirmOrder', 'processOrder', 'cancelLocally', 'addOrderComment',
                'failBuyerReturn',
            ])
            ->getMock();
        $reads = 0;
        $service->method('reloadOrder')->willReturnCallback(function () use (&$reads, $fresh, $afterRead) {
            return $reads++ === 0 ? $fresh : ($afterRead ?? $fresh);
        });
        $service->method('failBuyerReturn')->willReturnCallback(function ($order, \Throwable $exception) {
            $this->calls[] = ['failBuyerReturn', $exception->getMessage()];
            return 'shown to the buyer';
        });
        $record = function (string $call) use ($service, $twoOrder, $confirmRefused) {
            return function (...$args) use ($call, $service, $twoOrder, $confirmRefused) {
                $argument = in_array($call, ['addOrderComment', 'cancelLocally', 'processOrder'], true) ? (string)$args[1] : null;
                $this->calls[] = [$call, $argument];
                if ($call === 'getTwoOrderFromApi') {
                    if ($twoOrder instanceof \Exception) {
                        throw $twoOrder;
                    }
                    return $twoOrder + ['id' => 'two-1'];
                }
                if ($call === 'confirmOrder' && $confirmRefused) {
                    throw new \Exception('refused');
                }
                return $call === 'confirmOrder' ? [] : $service;
            };
        };
        foreach (['getTwoOrderFromApi', 'confirmOrder', 'processOrder', 'cancelLocally', 'addOrderComment'] as $method) {
            $service->method($method)->willReturnCallback($record($method));
        }

        $lockManager = $this->createMock(LockManagerInterface::class);
        $lockManager->method('lock')->willReturnCallback($lock ?? static fn () => true);
        // The check time and its comment are written on their own, never by
        // saving the whole order.
        $connection = new class ($test = $this) {
            private $test;

            public function __construct($test)
            {
                $this->test = $test;
            }

            public function update($table, array $bind, $where)
            {
                $this->test->assertSame('sales_order', $table);
                $this->test->assertSame([OrderService::PENDING_CHECKED_AT], array_keys($bind), 'only the check time');
                $this->test->assertSame(['entity_id = ?' => 42], $where);
                $this->test->record('stamp');
                return 1;
            }
        };
        $resource = new class ($connection) {
            private $connection;

            public function __construct($connection)
            {
                $this->connection = $connection;
            }

            public function getConnection()
            {
                return $this->connection;
            }

            public function getMainTable()
            {
                return 'sales_order';
            }
        };
        $historyRepository = new class ($test) {
            private $test;

            public function __construct($test)
            {
                $this->test = $test;
            }

            public function save($history)
            {
                $this->test->record('comment', (string)$history->getComment());
                return $history;
            }
        };
        $brand = $this->createMock(BrandRegistryInterface::class);
        $brand->method('getProductName')->willReturn('Two');
        $test = $this;
        $sender = new class ($test) {
            private $test;

            public function __construct($test)
            {
                $this->test = $test;
            }

            public function send($order)
            {
                $this->test->record('send');
                return true;
            }
        };

        $reflection = new \ReflectionClass(R::class);
        $resolver = $reflection->newInstanceWithoutConstructor();
        $values = [
            'orderService' => $service,
            'orderSender' => $sender,
            'lockManager' => $lockManager,
            'orderResource' => $resource,
            'historyFactory' => new HistoryFactory(),
            'historyRepository' => $historyRepository,
            'brandRegistry' => $brand,
            'logRepository' => $this->createMock(LogRepository::class),
        ];
        foreach ($values as $property => $value) {
            $reflection->getProperty($property)->setValue($resolver, $value);
        }
        return $resolver;
    }

    public function record(string $call, ?string $argument = null): void
    {
        $this->calls[] = [$call, $argument];
    }
}
