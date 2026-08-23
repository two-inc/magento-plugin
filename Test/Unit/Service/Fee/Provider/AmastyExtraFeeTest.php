<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Service\Fee\Provider;

use Magento\Sales\Model\Order as OrderModel;
use Magento\Sales\Model\Order\Creditmemo;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Service\Fee\Provider\AmastyExtraFee;
use Two\Gateway\Service\Fee\Provider\AmastyExtraFeeQuoteReader;

/**
 * AmastyExtraFee reads Amasty's fee by quote_id (via AmastyExtraFeeQuoteReader,
 * mocked here) rather than loading the order, because ComposeOrder hands it
 * the order mid-placement - before OrderRepositoryInterface::save() gives it
 * an entity_id. Covers: non-order entity, no quote_id yet, the reader
 * finding nothing (not installed or no fee selected), and the itemization
 * arithmetic for a taxed and an untaxed fee.
 */
class AmastyExtraFeeTest extends TestCase
{
    private function providerWithReader(AmastyExtraFeeQuoteReader $reader): AmastyExtraFee
    {
        return new AmastyExtraFee($reader);
    }

    private function orderWithQuoteId(?int $quoteId): OrderModel
    {
        $order = new OrderModel();
        if ($quoteId !== null) {
            $order->setQuoteId($quoteId);
        }
        return $order;
    }

    private function readerReturning(?array $fee): AmastyExtraFeeQuoteReader
    {
        $reader = $this->createMock(AmastyExtraFeeQuoteReader::class);
        $reader->method('getFeeByQuoteId')->willReturn($fee);
        return $reader;
    }

    public function testReturnsNoLineForNonOrderEntity(): void
    {
        $provider = $this->providerWithReader($this->readerReturning(null));

        $this->assertSame(
            [],
            $provider->getFeeLines(new Creditmemo()),
            'a Creditmemo/Invoice entity is out of scope for this provider'
        );
    }

    /**
     * @dataProvider noQuoteIdScenarioProvider
     */
    public function testReturnsNoLineWhenThereIsNoQuoteIdYet(?int $quoteId, string $description): void
    {
        $provider = $this->providerWithReader($this->readerReturning(['net_amount' => 5.99, 'tax_amount' => 1.2]));

        $this->assertSame([], $provider->getFeeLines($this->orderWithQuoteId($quoteId)), $description);
    }

    /** @return array<string, array{int|null, string}> */
    public function noQuoteIdScenarioProvider(): array
    {
        return [
            'no quote_id set at all' => [null, 'never reach the reader without a quote_id'],
            'quote_id is zero' => [0, 'a zero quote_id is not a real quote'],
        ];
    }

    /**
     * @dataProvider noLineScenarioProvider
     */
    public function testReturnsNoLineWhen(?array $fee, string $description): void
    {
        $provider = $this->providerWithReader($this->readerReturning($fee));

        $this->assertSame([], $provider->getFeeLines($this->orderWithQuoteId(1)), $description);
    }

    /** @return array<string, array{array|null, string}> */
    public function noLineScenarioProvider(): array
    {
        return [
            'reader finds nothing' => [
                null,
                'Amasty not installed, or no fee row for this quote - either way, nothing to itemize',
            ],
            'reader finds a zero fee' => [
                ['net_amount' => 0.0, 'tax_amount' => 0.0],
                'a zero fee amount means this order has no Amasty fee, not a fee worth £0',
            ],
        ];
    }

    /**
     * @dataProvider feeAmountScenarioProvider
     */
    public function testItemizesFeeAmount(float $netAmount, float $taxAmount, array $expectedLine, string $description): void
    {
        $provider = $this->providerWithReader(
            $this->readerReturning(['net_amount' => $netAmount, 'tax_amount' => $taxAmount])
        );

        $this->assertSame([$expectedLine], $provider->getFeeLines($this->orderWithQuoteId(1)), $description);
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
