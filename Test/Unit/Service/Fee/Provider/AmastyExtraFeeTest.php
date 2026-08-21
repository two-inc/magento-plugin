<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Service\Fee\Provider;

use Magento\Sales\Model\Order as OrderModel;
use Magento\Sales\Model\Order\Creditmemo;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Service\Fee\Provider\AmastyExtraFee;

/**
 * Amasty\Extrafee\Plugin\Order\OrderRepository populates
 * amextrafee_fee_amount/amextrafee_tax_amount extension attributes on
 * every order load, but only when amasty/module-extra-fee is actually
 * installed — on a merchant without it, the generated
 * OrderExtensionInterface never gained those methods at all. Covers both
 * that absence and the ordinary no-fee-on-this-order case, plus the
 * itemization arithmetic for a taxed and an untaxed fee.
 */
class AmastyExtraFeeTest extends TestCase
{
    private AmastyExtraFee $provider;

    protected function setUp(): void
    {
        $this->provider = new AmastyExtraFee();
    }

    private function orderWithExtensionAttributes($extensionAttributes): OrderModel
    {
        $order = new OrderModel();
        $order->setExtensionAttributes($extensionAttributes);
        return $order;
    }

    private function amastyExtensionAttributes(float $feeAmount, float $taxAmount)
    {
        return new class ($feeAmount, $taxAmount) {
            private float $feeAmount;
            private float $taxAmount;

            public function __construct(float $feeAmount, float $taxAmount)
            {
                $this->feeAmount = $feeAmount;
                $this->taxAmount = $taxAmount;
            }

            public function getAmextrafeeFeeAmount(): float
            {
                return $this->feeAmount;
            }

            public function getAmextrafeeTaxAmount(): float
            {
                return $this->taxAmount;
            }
        };
    }

    /**
     * @dataProvider noLineScenarioProvider
     */
    public function testReturnsNoLineWhen($entity, string $description): void
    {
        $this->assertSame([], $this->provider->getFeeLines($entity), $description);
    }

    /** @return array<string, array{mixed, string}> */
    public function noLineScenarioProvider(): array
    {
        return [
            'not an order entity' => [
                new Creditmemo(),
                'a Creditmemo/Invoice entity is out of scope for this provider',
            ],
            'order has no extension attributes at all' => [
                $this->orderWithExtensionAttributes(null),
                'getExtensionAttributes() returning null means nothing to itemize',
            ],
            'order extension attributes lack the Amasty getters' => [
                $this->orderWithExtensionAttributes(new class {
                }),
                'no amasty/module-extra-fee installed — the getters were never generated',
            ],
            'order has the Amasty getters but no fee was selected' => [
                $this->orderWithExtensionAttributes($this->amastyExtensionAttributes(0.0, 0.0)),
                'a zero fee amount means this order has no Amasty fee, not a fee worth £0',
            ],
        ];
    }

    /**
     * @dataProvider feeAmountScenarioProvider
     */
    public function testItemizesFeeAmount(float $netAmount, float $taxAmount, array $expectedLine, string $description): void
    {
        $order = $this->orderWithExtensionAttributes($this->amastyExtensionAttributes($netAmount, $taxAmount));

        $this->assertSame([$expectedLine], $this->provider->getFeeLines($order), $description);
    }

    /** @return array<string, array{float, float, array, string}> */
    public function feeAmountScenarioProvider(): array
    {
        return [
            'fee taxed at 20%' => [
                5.99,
                1.198,
                [
                    'order_item_id' => 'amasty_extrafee',
                    'name' => 'Amasty Fee',
                    'description' => 'Amasty Fee',
                    'type' => 'OTHER',
                    'image_url' => '',
                    'product_page_url' => '',
                    'gross_amount' => '7.19',
                    'net_amount' => '5.99',
                    'tax_amount' => '1.20',
                    'discount_amount' => '0.00',
                    'tax_rate' => '0.200000',
                    'tax_class_name' => 'VAT 20.00%',
                    'unit_price' => '5.990000',
                    'quantity' => 1,
                    'quantity_unit' => 'sc',
                ],
                'the exact case that reached checkout-api rejected: a taxed Amasty fee must land as a real line, not vanish from line_items',
            ],
            'fee with no tax' => [
                10.00,
                0.0,
                [
                    'order_item_id' => 'amasty_extrafee',
                    'name' => 'Amasty Fee',
                    'description' => 'Amasty Fee',
                    'type' => 'OTHER',
                    'image_url' => '',
                    'product_page_url' => '',
                    'gross_amount' => '10.00',
                    'net_amount' => '10.00',
                    'tax_amount' => '0.00',
                    'discount_amount' => '0.00',
                    'tax_rate' => '0.000000',
                    'tax_class_name' => 'VAT 0.00%',
                    'unit_price' => '10.000000',
                    'quantity' => 1,
                    'quantity_unit' => 'sc',
                ],
                'an untaxed fee still itemizes correctly with a 0% rate, not a division-by-zero',
            ],
        ];
    }
}
