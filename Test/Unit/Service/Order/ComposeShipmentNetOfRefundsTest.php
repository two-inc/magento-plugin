<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Service\Order;

use Magento\Catalog\Model\Product;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Shipment;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\Config\RepositoryInterface as ConfigRepository;
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
    private function composer(Order $order): ComposeShipment
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
            'feeLineProviderPool' => new FeeLineProviderPool([]),
        ] as $name => $value) {
            (new \ReflectionProperty(OrderService::class, $name))->setValue($service, $value);
        }
        $config = $this->createMock(ConfigRepository::class);
        $config->method('isTaxSubtotalsEnabled')->willReturn(true);
        $config->method('getWeightUnit')->willReturn('kg');
        $service->configRepository = $config;

        return $service;
    }
}
