<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Service\Order;

use Magento\Catalog\Model\Product;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\DataObject;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address;
use Magento\Quote\Model\Quote\Address\ToOrder;
use Magento\Quote\Model\Quote\Address\ToOrderAddress;
use Magento\Quote\Model\Quote\Item\ToOrderItem;
use Magento\Sales\Model\Order;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\Config\RepositoryInterface as ConfigRepository;
use Two\Gateway\Api\Log\RepositoryInterface as LogRepository;
use Two\Gateway\Service\Fee\FeeLineProviderPool;
use Two\Gateway\Service\Order as OrderService;
use Two\Gateway\Service\Order\ComposeIntent;
use Two\Gateway\Service\Order\ComposeOrder;

/**
 * Order intent composed server-side from the quote (TWO-26092) carries the
 * figures the browser used to build for the same cart.
 */
class ComposeIntentTest extends TestCase
{
    /**
     * What the Luma renderer's own placeOrderIntent() sent for a plain cart:
     * one product at 100.00 + 21% and 29.00 of untaxed shipping.
     */
    private const BROWSER_BODY = [
        'gross_amount' => '150.00',
        'net_amount' => '129.00',
        'tax_amount' => '21.00',
        'currency' => 'EUR',
        'line_items' => [
            ['gross_amount' => '121.00', 'net_amount' => '100.00', 'tax_amount' => '21.00', 'discount_amount' => '0.00', 'tax_rate' => '0.210000', 'quantity' => 1, 'type' => 'PHYSICAL'],
            ['gross_amount' => '29.00', 'net_amount' => '29.00', 'tax_amount' => '0.00', 'tax_rate' => '0.000000', 'quantity' => 1, 'type' => 'SHIPPING_FEE'],
        ],
        'buyer' => ['company' => ['organization_number' => '123456789', 'country_prefix' => 'NO']],
    ];

    /**
     * @dataProvider browserFields
     */
    public function testTheServerComposesWhatTheBrowserSent(string $pointer, string $description): void
    {
        $composed = $this->composeIntent()->execute($this->quote(), self::BROWSER_BODY['buyer']);

        $this->assertSame(self::at(self::BROWSER_BODY, $pointer), self::at($composed, $pointer), $description);
    }

    public static function browserFields(): array
    {
        return [
            ['/gross_amount', 'order gross'],
            ['/net_amount', 'order net'],
            ['/tax_amount', 'order tax'],
            ['/currency', 'currency'],
            ['/buyer', 'the buyer is relayed as the browser sent it'],
            ['/line_items/0/gross_amount', 'product gross'],
            ['/line_items/0/net_amount', 'product net'],
            ['/line_items/0/tax_amount', 'product tax'],
            ['/line_items/0/discount_amount', 'product discount'],
            ['/line_items/0/tax_rate', 'product rate'],
            ['/line_items/0/type', 'product type'],
            ['/line_items/1/gross_amount', 'shipping gross'],
            ['/line_items/1/net_amount', 'shipping net'],
            ['/line_items/1/tax_amount', 'shipping tax'],
            ['/line_items/1/tax_rate', 'shipping rate'],
            ['/line_items/1/type', 'shipping type'],
        ];
    }

    public function testTheBodyCarriesNoKeyTheBrowserDidNot(): void
    {
        $composed = $this->composeIntent()->execute($this->quote(), self::BROWSER_BODY['buyer']);

        $this->assertSame(array_keys(self::BROWSER_BODY), array_keys($composed));
        $this->assertCount(2, $composed['line_items']);
    }

    private function composeIntent(): ComposeIntent
    {
        $composeOrder = $this->getMockBuilder(ComposeOrder::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getProductImageUrl', 'getCategories'])
            ->getMock();
        $composeOrder->method('getProductImageUrl')->willReturn('');
        $composeOrder->method('getCategories')->willReturn([]);
        $config = $this->createMock(ConfigRepository::class);
        $config->method('getWeightUnit')->willReturn('kg');
        $composeOrder->configRepository = $config;
        foreach ([
            [OrderService::class, 'logRepository', $this->createMock(LogRepository::class)],
            [OrderService::class, 'feeLineProviderPool', new FeeLineProviderPool([])],
            [ComposeOrder::class, 'checkoutSession', new CheckoutSession()],
        ] as [$class, $property, $value]) {
            (new \ReflectionProperty($class, $property))->setValue($composeOrder, $value);
        }

        $order = new IntentOrderStub();
        $order->setData('store_id', 1);
        $order->setData('grand_total', 150.0);
        $order->setData('tax_amount', 21.0);
        $order->setData('shipping_amount', 29.0);
        $order->setData('shipping_tax_amount', 0.0);
        $order->setData('shipping_description', 'Flat Rate');
        $order->setData('order_currency_code', 'EUR');

        $item = new IntentOrderItemStub();
        $item->setData('name', 'Widget');
        $item->setData('row_total', 100.0);
        $item->setData('tax_amount', 21.0);
        $item->setData('tax_percent', 21.0);
        $item->setData('discount_amount', 0.0);
        $item->setData('qty_ordered', 1);
        $item->setData('product', new class extends Product {
            public function getCategoryIds()
            {
                return [];
            }
        });

        return new ComposeIntent(
            $composeOrder,
            new class ($order) extends ToOrder {
                /** @var Order */
                private $order;

                public function __construct(Order $order)
                {
                    $this->order = $order;
                }

                public function convert($address, $data = [])
                {
                    return $this->order;
                }
            },
            new class extends ToOrderAddress {
                public function convert($address, $data = [])
                {
                    return new DataObject($data);
                }
            },
            new class ($item) extends ToOrderItem {
                /** @var IntentOrderItemStub */
                private $item;

                public function __construct(IntentOrderItemStub $item)
                {
                    $this->item = $item;
                }

                public function convert($quoteItem, $data = [])
                {
                    return $this->item;
                }
            }
        );
    }

    private function quote(): Quote
    {
        return new class extends Quote {
            public function setTotalsCollectedFlag($flag)
            {
                return $this;
            }

            public function collectTotals()
            {
                return $this;
            }

            public function isVirtual()
            {
                return false;
            }

            public function getAllItems()
            {
                return [new DataObject(['id' => 5])];
            }

            public function getShippingAddress()
            {
                return new class extends Address {
                    public function getShippingMethod()
                    {
                        return 'flatrate_flatrate';
                    }
                };
            }

            public function getBillingAddress()
            {
                return new Address();
            }

            public function getQuoteCurrencyCode()
            {
                return 'EUR';
            }

            public function getCustomerGroupId()
            {
                return null;
            }

            public function getCustomerId()
            {
                return null;
            }
        };
    }

    /**
     * @param array $payload
     * @param string $pointer
     * @return mixed
     */
    private static function at(array $payload, string $pointer)
    {
        foreach (array_slice(explode('/', $pointer), 1) as $key) {
            $payload = $payload[$key] ?? null;
        }

        return $payload;
    }
}

/**
 * Real Order derives its visible items from the ones set on it.
 */
class IntentOrderStub extends Order
{
    public function getAllVisibleItems()
    {
        return array_values(array_filter(
            $this->getData('items') ?? [],
            static fn ($item) => !$item->getParentItemId()
        ));
    }
}

class IntentOrderItemStub extends \Magento\Sales\Model\Order\Item
{
    /** @var array */
    private $data = [];

    public function setData($key, $value = null)
    {
        $this->data[$key] = $value;
        return $this;
    }

    public function __call($method, $args)
    {
        $key = strtolower(preg_replace('/(.)([A-Z])/', '$1_$2', substr($method, 3)));
        if (strncmp($method, 'set', 3) === 0) {
            $this->data[$key] = $args[0] ?? null;
            return $this;
        }
        return $this->data[$key] ?? null;
    }
}
