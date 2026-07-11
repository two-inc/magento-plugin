<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Model\Total;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\DataObject;
use Magento\Quote\Api\Data\ShippingAssignmentInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address\Total;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\Config\RepositoryInterface as ConfigRepository;
use Two\Gateway\Api\Log\RepositoryInterface as LogRepository;
use Two\Gateway\Model\Total\Surcharge;
use Two\Gateway\Service\Order\SurchargeCalculator;
use Two\Gateway\Service\Order\SurchargeTaxCalculator;

/**
 * TWO-25072 coverage for the quote total collector's tax branching,
 * asserted on the exact fields downstream consumers read:
 *
 *  - Total data two_surcharge_[tax_]amount / two_surcharge_tax_rate —
 *    copied onto the order via the sales_convert_quote_address
 *    fieldset, then read by Service\Order\ComposeOrder to build the
 *    BUYER_FEE line item (net_amount / tax_amount / tax_rate) in
 *    Two's order-creation API payload.
 *  - Session TwoSurchargeAmount/Tax/TaxRate — ComposeOrder's fallback
 *    channel when the order columns are unpopulated.
 *
 * With a surcharge tax class configured the tax must come from the
 * tax rules engine (SurchargeTaxCalculator); without one, from the
 * legacy flat percentage.
 */
class SurchargeTest extends TestCase
{
    /** @var CheckoutSession */
    private $session;

    /** @var ConfigRepository|\PHPUnit\Framework\MockObject\MockObject */
    private $config;

    /** @var SurchargeCalculator|\PHPUnit\Framework\MockObject\MockObject */
    private $surchargeCalculator;

    /** @var SurchargeTaxCalculator|\PHPUnit\Framework\MockObject\MockObject */
    private $taxCalculator;

    /** @var Surcharge */
    private $collector;

    protected function setUp(): void
    {
        $this->session = new CheckoutSession();
        $this->config = $this->createMock(ConfigRepository::class);
        $this->surchargeCalculator = $this->createMock(SurchargeCalculator::class);
        $this->taxCalculator = $this->createMock(SurchargeTaxCalculator::class);

        $this->collector = new Surcharge(
            $this->session,
            $this->config,
            $this->surchargeCalculator,
            $this->taxCalculator,
            $this->createMock(LogRepository::class)
        );
    }

    private function makeQuote(): Quote
    {
        return new class extends Quote {
            public function getPayment()
            {
                return new DataObject(['method' => 'two_payment']);
            }

            public function getStoreId()
            {
                return 1;
            }

            public function getQuoteCurrencyCode()
            {
                return 'USD';
            }

            public function getBillingAddress()
            {
                return new DataObject(['countryId' => 'US']);
            }

            public function getShippingAddress()
            {
                return new DataObject(['countryId' => 'US']);
            }

            public function getBaseToQuoteRate()
            {
                return 1.0;
            }
        };
    }

    private function makeShippingAssignment(): ShippingAssignmentInterface
    {
        return new class implements ShippingAssignmentInterface {
            public function getItems()
            {
                return [new DataObject()];
            }

            public function getShipping()
            {
                return new DataObject(['address' => new DataObject(['countryId' => 'US'])]);
            }
        };
    }

    private function stubBaseline(): void
    {
        $this->config->method('getSurchargeType')->willReturn('percentage');
        $this->session->setTwoSelectedTerm(30);
        $this->surchargeCalculator->method('calculate')->willReturn([
            'amount' => 100.0,
            'tax_rate' => 21.0, // legacy flat rate from config, via pricing result
            'description' => 'Payment terms fee - 30 days',
        ]);
    }

    public function testEngineTaxUsedWhenTaxClassConfigured(): void
    {
        $this->stubBaseline();
        $this->config->method('getSurchargeTaxClassId')->willReturn(4);
        // Engine resolves a combined US state+local 7.25% for this destination.
        $this->taxCalculator->expects($this->once())
            ->method('calculateForQuote')
            ->with(
                $this->anything(),
                $this->anything(),
                100.0,
                100.0,
                4,
                1
            )
            ->willReturn(['tax_amount' => 7.25, 'base_tax_amount' => 7.25, 'tax_rate' => 7.25]);

        $total = new Total(['grand_total' => 1000.0, 'base_grand_total' => 1000.0]);
        $this->collector->collect($this->makeQuote(), $this->makeShippingAssignment(), $total);

        // Fields the conversion fieldset copies to the order and
        // ComposeOrder forwards to Two's API as the BUYER_FEE line.
        $this->assertEqualsWithDelta(100.0, $total->getData('two_surcharge_amount'), 1e-9);
        $this->assertEqualsWithDelta(7.25, $total->getData('two_surcharge_tax_amount'), 1e-9);
        $this->assertEqualsWithDelta(7.25, $total->getData('two_surcharge_tax_rate'), 1e-9);
        $this->assertEqualsWithDelta(1107.25, $total->getGrandTotal(), 1e-9);
        $this->assertEqualsWithDelta(7.25, $total->getTaxAmount(), 1e-9);

        // ComposeOrder's session fallback channel.
        $this->assertEqualsWithDelta(100.0, $this->session->getTwoSurchargeAmount(), 1e-9);
        $this->assertEqualsWithDelta(7.25, $this->session->getTwoSurchargeTax(), 1e-9);
        $this->assertEqualsWithDelta(107.25, $this->session->getTwoSurchargeGross(), 1e-9);
        $this->assertEqualsWithDelta(7.25, $this->session->getTwoSurchargeTaxRate(), 1e-9);
    }

    public function testEngineZeroForUnmatchedDestinationStillZeroTax(): void
    {
        $this->stubBaseline();
        $this->config->method('getSurchargeTaxClassId')->willReturn(99);
        // No Tax Rule matches (e.g. the provisioned no-tax class).
        $this->taxCalculator->method('calculateForQuote')
            ->willReturn(['tax_amount' => 0.0, 'base_tax_amount' => 0.0, 'tax_rate' => 0.0]);

        $total = new Total(['grand_total' => 1000.0, 'base_grand_total' => 1000.0]);
        $this->collector->collect($this->makeQuote(), $this->makeShippingAssignment(), $total);

        $this->assertEqualsWithDelta(100.0, $total->getData('two_surcharge_amount'), 1e-9);
        $this->assertEqualsWithDelta(0.0, $total->getData('two_surcharge_tax_amount'), 1e-9);
        $this->assertEqualsWithDelta(1100.0, $total->getGrandTotal(), 1e-9);
        $this->assertEqualsWithDelta(0.0, $this->session->getTwoSurchargeTax(), 1e-9);
    }

    public function testLegacyFlatRateWhenNoTaxClassConfigured(): void
    {
        $this->stubBaseline();
        $this->config->method('getSurchargeTaxClassId')->willReturn(null);
        $this->taxCalculator->expects($this->never())->method('calculateForQuote');

        $total = new Total(['grand_total' => 1000.0, 'base_grand_total' => 1000.0]);
        $this->collector->collect($this->makeQuote(), $this->makeShippingAssignment(), $total);

        // Pre-existing behaviour: net * flat rate.
        $this->assertEqualsWithDelta(21.0, $total->getData('two_surcharge_tax_amount'), 1e-9);
        $this->assertEqualsWithDelta(21.0, $total->getData('two_surcharge_tax_rate'), 1e-9);
        $this->assertEqualsWithDelta(1121.0, $total->getGrandTotal(), 1e-9);
        $this->assertEqualsWithDelta(21.0, $this->session->getTwoSurchargeTax(), 1e-9);
    }
}
