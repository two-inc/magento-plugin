<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Service\Order;

use Magento\Framework\DataObject;
use Magento\Sales\Model\Order as OrderModel;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Service\Order;

/**
 * Anything composition itemizes but this omits reads as an unitemized fee and
 * gets refunded as one, so the line set and the amounts both have to match.
 */
class KnownLineAmountsOrderTest extends TestCase
{
    private function makeItem(float $rowTotal, float $tax, float $discount): DataObject
    {
        $item = new DataObject();
        $item->setRowTotal($rowTotal);
        $item->setTaxAmount($tax);
        $item->setDiscountAmount($discount);
        $item->setDiscountTaxCompensationAmount(0.0);

        return $item;
    }

    private function makeOrder(array $items, bool $virtual, float $shipping, float $surchargeNet): OrderModel
    {
        $order = new OrderModel();
        $order->setData('all_visible_items', $items);
        $order->setData('is_virtual', $virtual);
        $order->setData('shipping_amount', $shipping);
        $order->setData('two_surcharge_amount', $surchargeNet);
        $order->setData('two_surcharge_tax_amount', $surchargeNet > 0 ? 2.0 : 0.0);

        return $order;
    }

    private function makeService(array $mockedMethods = []): Order
    {
        $builder = $this->getMockBuilder(Order::class)->disableOriginalConstructor();
        if ($mockedMethods) {
            $builder->onlyMethods($mockedMethods);
        }

        return $builder->getMockForAbstractClass();
    }

    /**
     * Real amount accessors: net is row total less discount, tax is the item's
     * own, gross is their sum. Expectations are computed here independently of
     * the accessors so this cannot pass by echoing them.
     *
     * @dataProvider itemAmountProvider
     */
    public function testItemGrossAndTaxAreTheAmountsCompositionDeclares(
        array $rows,
        float $expectedGross,
        float $expectedTax,
        string $description
    ): void {
        $items = array_map(fn (array $r) => $this->makeItem($r[0], $r[1], $r[2]), $rows);
        $order = $this->makeOrder($items, true, 0.0, 0.0);

        $amounts = $this->makeService()->getKnownLineAmountsOrder($order);

        $this->assertCount(count($rows), $amounts, $description);
        $this->assertEqualsWithDelta($expectedGross, $this->sum($amounts, 'gross_amount'), 0.0001, $description);
        $this->assertEqualsWithDelta($expectedTax, $this->sum($amounts, 'tax_amount'), 0.0001, $description);
    }

    public static function itemAmountProvider(): array
    {
        return [
            [[[50.0, 10.0, 0.0]], 60.0, 10.0, 'one undiscounted item'],
            [[[50.0, 10.0, 5.0]], 55.0, 10.0, 'discount reduces net and so gross, never tax'],
            [[[50.0, 10.0, 0.0], [20.0, 4.0, 2.0]], 82.0, 14.0, 'two items, one discounted'],
            [[[50.0, 0.0, 0.0]], 50.0, 0.0, 'an untaxed item'],
        ];
    }

    /**
     * The product is never consulted, so no item can drop out the way
     * getLineItemsOrder() drops one whose product has been deleted.
     */
    public function testEveryVisibleItemIsCountedWithoutTouchingTheProduct(): void
    {
        $service = $this->makeService(['getProduct']);
        $service->expects($this->never())->method('getProduct');
        $items = [$this->makeItem(10.0, 0.0, 0.0), $this->makeItem(20.0, 0.0, 0.0)];

        $amounts = $service->getKnownLineAmountsOrder($this->makeOrder($items, true, 0.0, 0.0));

        $this->assertCount(2, $amounts);
        $this->assertEqualsWithDelta(30.0, $this->sum($amounts, 'gross_amount'), 0.0001);
    }

    /**
     * @dataProvider lineSetProvider
     */
    public function testShippingAndSurchargeLinesAppearExactlyWhenCompositionEmitsThem(
        bool $virtual,
        float $shipping,
        float $surchargeNet,
        int $expectedLines,
        float $expectedGross,
        string $description
    ): void {
        $service = $this->makeService(['getGrossAmountShipping', 'getTaxAmountShipping']);
        $service->method('getGrossAmountShipping')->willReturn(12.0);
        $service->method('getTaxAmountShipping')->willReturn(2.0);
        $order = $this->makeOrder([$this->makeItem(50.0, 10.0, 0.0)], $virtual, $shipping, $surchargeNet);

        $amounts = $service->getKnownLineAmountsOrder($order);

        $this->assertCount($expectedLines, $amounts, $description);
        $this->assertEqualsWithDelta($expectedGross, $this->sum($amounts, 'gross_amount'), 0.0001, $description);
    }

    public static function lineSetProvider(): array
    {
        return [
            [false, 5.0, 0.0, 2, 72.0, 'item plus shipping'],
            [false, 5.0, 8.0, 3, 82.0, 'item, shipping and surcharge'],
            [true, 0.0, 0.0, 1, 60.0, 'a virtual order has no shipping line'],
            [false, 0.0, 0.0, 1, 60.0, 'free shipping adds no line'],
            [true, 0.0, 8.0, 2, 70.0, 'surcharge without shipping'],
        ];
    }

    private function sum(array $amounts, string $key): float
    {
        return array_sum(array_map(static fn (array $l) => (float)$l[$key], $amounts));
    }
}
