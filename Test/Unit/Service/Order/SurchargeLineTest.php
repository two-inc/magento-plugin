<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Service\Order;

use PHPUnit\Framework\TestCase;
use Two\Gateway\Service\Order\ComposeShipment;

/**
 * The surcharge line every payload (create, capture, refund, fulfilment)
 * carries comes from the one builder, Service\Order::getSurchargeLine().
 */
class SurchargeLineTest extends TestCase
{
    /**
     * Each case is [net, tax, description, rate %] and the fields of the line
     * that case is about.
     *
     * @dataProvider cases
     */
    public function testBuildsTheSurchargeLine(
        float $net,
        float $tax,
        string $name,
        float $ratePercent,
        array $expected,
        string $description
    ): void {
        $service = $this->getMockBuilder(ComposeShipment::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getMock();

        $line = $service->getSurchargeLine($net, $tax, $name, $ratePercent);

        $this->assertSame($expected, array_intersect_key($line, $expected), $description);
    }

    public static function cases(): array
    {
        return [
            [10.0, 2.5, 'Terms fee', 25.0, [
                'order_item_id' => 'surcharge', 'name' => 'Terms fee', 'description' => 'Terms fee', 'type' => 'BUYER_FEE',
                'gross_amount' => '12.50', 'net_amount' => '10.00', 'tax_amount' => '2.50', 'discount_amount' => '0.00',
                'tax_rate' => '0.250000', 'tax_class_name' => 'VAT 25.00%', 'unit_price' => '10.000000',
                'quantity' => 1, 'quantity_unit' => 'sc',
            ], 'a whole-cent surcharge is one BUYER_FEE line'],
            [10.004, 2.004, 'Terms fee', 25.0, [
                'gross_amount' => '12.01', 'net_amount' => '10.00', 'tax_amount' => '2.00', 'unit_price' => '10.004000',
            ], 'gross is rounded from the unrounded sum and unit price kept at 6dp'],
            [10.0, 2.5, '', 25.0, ['name' => 'Payment terms fee', 'description' => 'Payment terms fee'], 'no description falls back to the default name'],
            [10.0, 1.25, 'Terms fee', 12.5, ['tax_rate' => '0.125000', 'tax_class_name' => 'VAT 12.50%'], 'a fractional rate keeps its rate and class name'],
        ];
    }
}
