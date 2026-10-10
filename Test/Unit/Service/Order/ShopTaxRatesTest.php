<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Service\Order;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\GroupRepositoryInterface;
use Magento\Framework\DataObject;
use Magento\Sales\Model\Order;
use Magento\Tax\Model\Calculation;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Service\Order\ShopTaxRates;
use Two\Gateway\Test\Stubs\UnderscoreDataObject;

/**
 * TWO-26153: the shop's rates for a line, 0% included, through core's calculation.
 */
class ShopTaxRatesTest extends TestCase
{
    public function testTheRatesComeFromCoreWithTheOrdersAddressesGroupClassAndTheProductClass(): void
    {
        $shipping = new UnderscoreDataObject(['country_id' => 'ES']);
        $billing = new UnderscoreDataObject(['country_id' => 'DE']);
        $order = new class ($shipping, $billing) extends Order {
            /** @var array */
            private $addresses;

            public function __construct(...$addresses)
            {
                $this->addresses = $addresses;
            }

            public function getShippingAddress()
            {
                return $this->addresses[0];
            }

            public function getBillingAddress()
            {
                return $this->addresses[1];
            }
        };
        $order->setData('customer_group_id', 4);
        $order->setData('store_id', 2);
        $groups = $this->createMock(GroupRepositoryInterface::class);
        $groups->method('getById')->with(4)->willReturn(new UnderscoreDataObject(['tax_class_id' => 3]));

        $calculation = $this->createMock(Calculation::class);
        $calculation->expects($this->once())->method('getRateRequest')
            ->with($shipping, $billing, 3, 2, null)
            ->willReturn(new DataObject(['countryId' => 'es', 'postcode' => '35001']));
        $classes = [];
        $calculation->method('getAppliedRates')->willReturnCallback(function (DataObject $request) use (&$classes) {
            $classes[] = $request->getProductClassId();
            return [
                ['percent' => 0, 'rates' => [['code' => 'ES-CANARIAS-0', 'percent' => 0, 'rule_id' => 1]]],
                ['percent' => 0, 'rates' => [['code' => 'ES-OTHER-0', 'percent' => '0.0000', 'rule_id' => 2]]],
            ];
        });

        $shop = new ShopTaxRates($calculation, $groups, $this->createMock(CustomerRepositoryInterface::class));

        $this->assertSame(['country' => 'ES', 'postcode' => '35001'], $shop->taxAddress($order));
        $this->assertSame(
            [['code' => 'ES-CANARIAS-0', 'percent' => 0.0], ['code' => 'ES-OTHER-0', 'percent' => 0.0]],
            $shop->rates($order, 5)
        );
        $shop->rates($order, 7);
        $this->assertSame([5, 7], $classes, 'each lookup carries its product class; the request is built once');
    }
}
