<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Service\Order;

use Magento\Catalog\Model\Product;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Creditmemo;
use Magento\Sales\Model\Order\Shipment;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\Config\RepositoryInterface as ConfigRepository;
use Two\Gateway\Api\Fee\FeeLineProviderInterface;
use Two\Gateway\Api\Log\RepositoryInterface as LogRepository;
use Two\Gateway\Service\Fee\FeeLineProviderPool;
use Two\Gateway\Service\Order as OrderService;
use Two\Gateway\Service\Order\ComposeShipment;

/**
 * TWO-26302: a fulfilment net of Magento refunds is the partial shipment of
 * what is left, composed by the same line builder.
 */
class ComposeShipmentNetOfRefundsTest extends TestCase
{
    /** @var Order|null The order the memo collection was filtered on. */
    public $filteredOrder;

    /**
     * Items are [id, ordered, refunded, canceled]; the expected shipment lists
     * [id, qty] and whether shipping goes in.
     *
     * @dataProvider cases
     */
    public function testMatchesAShipmentOfTheNetQuantity(
        array $items,
        float $shippingRefunded,
        array $shipped,
        bool $withShipping,
        string $description
    ): void {
        $order = $this->order($items, $shippingRefunded);
        $composer = $this->composer($order);

        $shipment = new class extends Shipment {
            /** @var array */
            public $items = [];

            /** @var int */
            public $id = 5;

            public function getAllItems()
            {
                return $this->items;
            }

            public function getId()
            {
                return $this->id;
            }
        };
        foreach ($shipped as [$id, $qty]) {
            $shipment->items[] = $this->item(['order_item_id' => $id, 'qty' => $qty, 'name' => "Item $id", 'sku' => "SKU$id"]);
        }
        // The shipping line rides on the first shipment only.
        $shipment->id = $withShipping ? 5 : 6;

        $this->assertEquals(
            $composer->execute($shipment, $order),
            $composer->executeNetOfRefunds($order),
            $description
        );
    }

    /**
     * One item of two, one unit refunded, so the fulfilment is a net partial.
     * The surcharge is [net, tax, refunded net]; the fee is [net, tax, the net
     * each saved memo refunded of it]; the residual is [an untaxed amount the
     * grand total carries beyond every known line, what each saved memo
     * refunded of it]. Memo i carries refund i of each. Expected lines are
     * [order_item_id, gross, net, tax, quantity], in that order; the order's
     * fee line is for a quantity of two.
     *
     * @dataProvider chargeCases
     */
    public function testCarriesTheChargesNotYetRefunded(
        ?array $surcharge,
        ?array $fee,
        ?array $residual,
        array $expected,
        string $description
    ): void {
        $order = $this->order([[1, 2, 1, 0]], 0.0);
        $memos = [];
        $feeLines = [];
        for ($i = 0; $i < max(count($fee[2] ?? []), count($residual[1] ?? []), 1); $i++) {
            $memo = (new Creditmemo())->setId(9 + $i)->setState(Creditmemo::STATE_REFUNDED)
                ->setTwoOtherChargesAmount($residual[1][$i] ?? 0.0);
            $memos[] = $memo;
            if (isset($fee[2][$i])) {
                array_push($feeLines, $memo, $this->feeLine($fee[2][$i], $fee[2][$i] * 0.25));
            }
        }
        // A memo not yet saved, or cancelled, refunded nothing.
        $cancelled = (new Creditmemo())->setId(20)->setState(Creditmemo::STATE_CANCELED)->setTwoOtherChargesAmount(50.0);
        $memos[] = $cancelled;
        $memos[] = (new Creditmemo())->setTwoOtherChargesAmount(50.0);
        $grandTotal = 260.00;
        $taxTotal = 52.00;
        if ($surcharge) {
            [$net, $tax, $refunded] = $surcharge;
            $order->setTwoSurchargeAmount($net)
                ->setTwoSurchargeTaxAmount($tax)
                ->setTwoSurchargeTaxRate(25.0)
                ->setTwoSurchargeRefunded($refunded)
                ->setTwoSurchargeDescription('Terms fee');
            $grandTotal += $net + $tax;
            $taxTotal += $tax;
        }
        if ($fee) {
            [$net, $tax] = $fee;
            array_push($feeLines, $order, $this->feeLine($net, $tax, 2), $cancelled, $this->feeLine($net, $tax));
            $grandTotal += $net + $tax;
            $taxTotal += $tax;
        }
        $grandTotal += $residual[0] ?? 0.0;
        $order->setGrandTotal($grandTotal);
        $order->setTaxAmount($taxTotal);

        $lines = [];
        $payload = $this->composer($order, $feeLines, $memos)->executeNetOfRefunds($order);
        $this->assertNotContains(null, $payload['line_items'], $description);
        foreach ($payload['line_items'] as $line) {
            if (in_array($line['type'], ['BUYER_FEE', 'OTHER'], true)) {
                $lines[] = [$line['order_item_id'], $line['gross_amount'], $line['net_amount'], $line['tax_amount'], $line['quantity']];
            }
        }

        $this->assertSame($expected, $lines, $description);
    }

    public static function chargeCases(): array
    {
        return [
            [[10.0, 2.5, 0.0], null, null, [['surcharge', '12.50', '10.00', '2.50', 1]], 'a surcharge with nothing refunded goes in whole'],
            [[10.0, 2.5, 4.0], null, null, [['surcharge', '7.50', '6.00', '1.50', 1]], 'a partly refunded surcharge goes in net, at its rate'],
            [[10.0, 2.5, 10.0], null, null, [], 'a fully refunded surcharge is left out'],
            [[10.005, 2.50125, 10.005], null, null, [], 'a fully refunded sub-cent surcharge is left out, not billed the rounding cent'],
            [[10.005, 2.50125, 4.0], null, null, [['surcharge', '7.51', '6.01', '1.50', 1]], 'a sub-cent surcharge is netted from its source amounts'],
            [null, [8.0, 2.0, []], null, [['fee_1', '10.00', '8.00', '2.00', 2]], 'a provider fee with nothing refunded goes in whole'],
            [null, [8.0, 2.0, [3.0]], null, [['fee_1', '6.25', '5.00', '1.25', 1]], 'a partly refunded provider fee goes in net of the memo line'],
            [null, [8.0, 2.0, [3.0, 2.0]], null, [['fee_1', '3.75', '3.00', '0.75', 1]], 'a provider fee goes in net of every memo line, summed'],
            [null, [8.0, 2.0, [8.0]], null, [], 'a fully refunded provider fee is left out'],
            [null, null, [4.0, []], [['other_charges', '4.00', '4.00', '0.00', 1]], 'an other-charges residual with nothing refunded goes in whole'],
            [null, null, [4.0, [1.5]], [['other_charges', '2.50', '2.50', '0.00', 1]], 'an other-charges residual goes in net of saved memos'],
            [null, null, [4.0, [1.5, 1.0]], [['other_charges', '1.50', '1.50', '0.00', 1]], 'an other-charges residual goes in net of every saved memo, summed'],
            [null, null, [4.0, [4.0]], [], 'a fully refunded other-charges residual is left out'],
            [[10.0, 2.5, 4.0], [8.0, 2.0, [3.0]], [4.0, [1.5]], [
                ['surcharge', '7.50', '6.00', '1.50', 1],
                ['fee_1', '6.25', '5.00', '1.25', 1],
                ['other_charges', '2.50', '2.50', '0.00', 1],
            ], 'all three together, none counted twice'],
        ];
    }

    /**
     * The order caches its credit memo collection once loaded, so a memo saved
     * after OtherCharges::collect() loaded it (the one moving the order into
     * the fulfil-on status) is missing from it. Memos come from a fresh query.
     */
    public function testReadsSavedMemosFreshNotFromTheOrderCache(): void
    {
        $order = $this->order([[1, 2, 1, 0]], 0.0);
        $earlier = (new Creditmemo())->setId(9)->setTwoOtherChargesAmount(1.0);
        $order->setCreditmemosCollection([$earlier]);
        $order->setGrandTotal(264.00);
        $order->setTaxAmount(52.00);
        $composer = $this->composer($order, [], [$earlier, (new Creditmemo())->setId(10)->setTwoOtherChargesAmount(1.5)]);

        $lines = array_values(array_filter(
            $composer->executeNetOfRefunds($order)['line_items'],
            static fn ($line) => $line['order_item_id'] === 'other_charges'
        ));

        $this->assertSame('1.50', $lines[0]['net_amount'], 'the memo saved in this refund is netted out too');
        $this->assertSame($order, $this->filteredOrder, 'the fresh query is filtered on the order');
    }

    private function feeLine(float $net, float $tax, int $quantity = 1): array
    {
        return [
            'order_item_id' => 'fee_1',
            'name' => 'Handling',
            'description' => 'Handling',
            'type' => 'OTHER',
            'gross_amount' => number_format($net + $tax, 2, '.', ''),
            'net_amount' => number_format($net, 2, '.', ''),
            'tax_amount' => number_format($tax, 2, '.', ''),
            'discount_amount' => '0.00',
            'tax_rate' => '0.250000',
            'tax_class_name' => 'VAT 25%',
            'unit_price' => number_format($net / $quantity, 6, '.', ''),
            'quantity' => $quantity,
            'quantity_unit' => 'sc',
        ];
    }

    public static function cases(): array
    {
        return [
            [[[1, 2, 0, 0]], 0.0, [[1, 2]], true, 'nothing refunded is the whole order'],
            [[[1, 2, 1, 0]], 0.0, [[1, 1]], true, 'a refunded unit is left out'],
            [[[1, 2, 0, 0], [2, 1, 1, 0]], 0.0, [[1, 2]], true, 'a fully refunded item is left out'],
            [[[1, 3, 1, 1]], 0.0, [[1, 1]], true, 'cancelled quantity is left out too'],
            [[[1, 2, 0, 0]], 4.0, [[1, 2]], false, 'shipping is left out once any of it was refunded'],
        ];
    }

    private function order(array $items, float $shippingRefunded): Order
    {
        $order = new class extends Order {
            /** @var array */
            public $itemsById = [];


            public function getItemById($id)
            {
                return $this->itemsById[$id];
            }

            public function getAllVisibleItems()
            {
                return array_values($this->itemsById);
            }
        };
        foreach ($items as [$id, $ordered, $refunded, $canceled]) {
            $order->itemsById[$id] = $this->item([
                'item_id' => $id,
                'name' => "Item $id",
                'sku' => "SKU$id",
                'qty_ordered' => $ordered,
                'qty_refunded' => $refunded,
                'qty_canceled' => $canceled,
                'row_total' => 100.00 * $ordered,
                'tax_amount' => 25.00 * $ordered,
                'tax_percent' => 25.0,
                'discount_amount' => 0.0,
            ]);
        }
        $order->setStoreId(1);
        $order->setOrderCurrencyCode('EUR');
        $order->setShippingDescription('Flat Rate');
        $order->setShippingMethod('flatrate_flatrate');
        $order->setIsVirtual(0);
        $order->setShippingAmount(8.00);
        $order->setShippingTaxAmount(2.00);
        $order->setShippingInclTax(10.00);
        $order->setShippingRefunded($shippingRefunded);
        $order->setCreditmemosCollection([]);
        // Balanced, so no other-charges residual unless a case adds one.
        $order->setGrandTotal(array_sum(array_map(static fn ($i) => 125.00 * $i[1], $items)) + 10.00);
        $order->setTaxAmount(array_sum(array_map(static fn ($i) => 25.00 * $i[1], $items)) + 2.00);
        $order->setShipmentsCollection(new class {
            public function getFirstItem()
            {
                return new \Magento\Framework\DataObject(['id' => 5]);
            }
        });

        return $order;
    }

    private function item(array $data): Order\Item
    {
        return new class ($data) extends Order\Item implements \Magento\Sales\Api\Data\OrderItemInterface {
            /** @var array */
            private $data;

            public function __construct(array $data)
            {
                $this->data = $data;
            }

            public function __call($method, $args)
            {
                $key = strtolower(preg_replace('/(.)([A-Z])/', '$1_$2', substr($method, 3)));
                return $this->data[$key] ?? null;
            }
        };
    }

    /**
     * A real composer; only the catalogue and repository lookups are stubbed.
     */
    private function composer(Order $order, array $feeLines = [], array $memos = []): ComposeShipment
    {
        $service = $this->getMockBuilder(ComposeShipment::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getProduct', 'getProductImageUrl', 'getCategories', 'getOrderItem'])
            ->getMock();
        $service->method('getProduct')->willReturn(new class extends Product {
            public function getCategoryIds()
            {
                return [];
            }

            public function getProductUrl()
            {
                return '';
            }
        });
        $service->method('getProductImageUrl')->willReturn('');
        $service->method('getCategories')->willReturn([]);
        $service->method('getOrderItem')->willReturnCallback(static fn (int $id) => $order->getItemById($id));
        foreach ([
            'logRepository' => $this->createMock(LogRepository::class),
            'feeLineProviderPool' => new FeeLineProviderPool([$this->feeProvider($feeLines)]),
            'creditmemoCollectionFactory' => $this->memoCollectionFactory($memos),
        ] as $name => $value) {
            (new \ReflectionProperty(OrderService::class, $name))->setValue($service, $value);
        }
        $config = $this->createMock(ConfigRepository::class);
        $config->method('isTaxSubtotalsEnabled')->willReturn(true);
        $config->method('getWeightUnit')->willReturn('kg');
        $service->configRepository = $config;

        return $service;
    }

    /**
     * A Creditmemo\CollectionFactory whose collection yields $memos once
     * filtered on an order, which it records.
     */
    private function memoCollectionFactory(array $memos): object
    {
        $test = $this;

        return new class ($memos, $test) {
            /** @var array */
            private $memos;

            /** @var ComposeShipmentNetOfRefundsTest */
            private $test;

            public function __construct(array $memos, ComposeShipmentNetOfRefundsTest $test)
            {
                $this->memos = $memos;
                $this->test = $test;
            }

            public function create(): object
            {
                return new class ($this->memos, $this->test) {
                    /** @var array */
                    private $memos;

                    /** @var ComposeShipmentNetOfRefundsTest */
                    private $test;

                    public function __construct(array $memos, ComposeShipmentNetOfRefundsTest $test)
                    {
                        $this->memos = $memos;
                        $this->test = $test;
                    }

                    public function setOrderFilter($order): iterable
                    {
                        $this->test->filteredOrder = $order;
                        return $this->memos;
                    }
                };
            }
        };
    }

    /**
     * A provider answering $feeLines as [entity, line, entity, line, ...].
     */
    private function feeProvider(array $feeLines): FeeLineProviderInterface
    {
        return new class ($feeLines) implements FeeLineProviderInterface {
            /** @var array */
            private $feeLines;

            public function __construct(array $feeLines)
            {
                $this->feeLines = $feeLines;
            }

            public function getFeeLines($entity): array
            {
                $lines = [];
                for ($i = 0; $i < count($this->feeLines); $i += 2) {
                    if ($this->feeLines[$i] === $entity) {
                        $lines[] = $this->feeLines[$i + 1];
                    }
                }

                return $lines;
            }
        };
    }
}
