<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Service\Order;

use Magento\Customer\Api\Data\AddressInterfaceFactory;
use Magento\Customer\Api\Data\RegionInterfaceFactory;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Quote\Api\Data\ShippingAssignmentInterface;
use Magento\Quote\Model\Quote;
use Magento\Tax\Api\Data\QuoteDetailsInterfaceFactory;
use Magento\Tax\Api\Data\QuoteDetailsItemInterfaceFactory;
use Magento\Tax\Api\Data\TaxClass;
use Magento\Tax\Api\Data\TaxClassKeyInterface;
use Magento\Tax\Api\Data\TaxClassKeyInterfaceFactory;
use Magento\Tax\Api\Data\TaxDetails;
use Magento\Tax\Api\Data\TaxDetailsItem;
use Magento\Tax\Api\TaxCalculationInterface;
use Magento\Tax\Api\TaxClassRepositoryInterface;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\Log\RepositoryInterface as LogRepository;
use Two\Gateway\Service\Order\SurchargeTaxCalculator;

/**
 * Coverage for TWO-25072: surcharge tax through Magento's real tax
 * rules engine (shipping-tax pattern) instead of a flat admin rate.
 *
 * TaxCalculationInterface is mocked (no live Magento install); the
 * mock simulates rule resolution outcomes — a combined additive
 * multi-rate jurisdiction (US state + local), a destination with no
 * matching rule, and the always-zero provisioned class.
 */
class SurchargeTaxCalculatorTest extends TestCase
{
    /** @var TaxCalculationInterface|\PHPUnit\Framework\MockObject\MockObject */
    private $taxCalculation;

    /** @var TaxClassRepositoryInterface|\PHPUnit\Framework\MockObject\MockObject */
    private $taxClassRepository;

    /** @var LogRepository|\PHPUnit\Framework\MockObject\MockObject */
    private $log;

    /** @var SurchargeTaxCalculator */
    private $calculator;

    /** @var \Magento\Tax\Api\Data\QuoteDetailsInterface[] QuoteDetails captured per calculateTax() call */
    private $capturedQuoteDetails = [];

    protected function setUp(): void
    {
        $this->taxCalculation = $this->createMock(TaxCalculationInterface::class);
        $this->taxClassRepository = $this->createMock(TaxClassRepositoryInterface::class);
        $this->log = $this->createMock(LogRepository::class);
        $this->capturedQuoteDetails = [];

        $this->calculator = new SurchargeTaxCalculator(
            $this->taxCalculation,
            new QuoteDetailsInterfaceFactory(),
            new QuoteDetailsItemInterfaceFactory(),
            new TaxClassKeyInterfaceFactory(),
            new AddressInterfaceFactory(),
            new RegionInterfaceFactory(),
            $this->taxClassRepository,
            $this->log
        );
    }

    /**
     * Simulate the tax engine resolving rules at a rate: the returned
     * TaxDetails item taxes the submitted unit price at $ratePercent
     * (additive sub-rates arrive from Magento as one combined percent —
     * e.g. CA 6% state + 1.25% local = 7.25).
     */
    private function stubEngineRate(float $ratePercent): void
    {
        $this->taxCalculation->method('calculateTax')->willReturnCallback(
            function ($quoteDetails) use ($ratePercent) {
                $this->capturedQuoteDetails[] = $quoteDetails;
                $item = $quoteDetails->getItems()[0];
                $rowTax = (float)$item->getUnitPrice() * (float)$item->getQuantity() * $ratePercent / 100;
                $detailsItem = new TaxDetailsItem([
                    'code' => $item->getCode(),
                    'rowTax' => $rowTax,
                    'taxPercent' => $ratePercent,
                ]);
                return new TaxDetails(['items' => [$item->getCode() => $detailsItem]]);
            }
        );
    }

    private function makeQuote(DataObject $billingAddress): Quote
    {
        return new class($billingAddress) extends Quote {
            /** @var DataObject */
            private $billing;

            public function __construct(DataObject $billing)
            {
                $this->billing = $billing;
            }

            public function getBillingAddress()
            {
                return $this->billing;
            }

            public function getCustomerTaxClassId()
            {
                return 3;
            }

            public function getCustomerId()
            {
                return 42;
            }
        };
    }

    private function makeShippingAssignment(DataObject $address): ShippingAssignmentInterface
    {
        return new class($address) implements ShippingAssignmentInterface {
            /** @var DataObject */
            private $address;

            public function __construct(DataObject $address)
            {
                $this->address = $address;
            }

            public function getShipping()
            {
                return new DataObject(['address' => $this->address]);
            }
        };
    }

    private function stubRegularTaxClass(): void
    {
        $this->taxClassRepository->method('get')->willReturn(
            new TaxClass(['className' => 'Taxable Goods'])
        );
    }

    private function usAddress(): DataObject
    {
        return new DataObject([
            'countryId' => 'US',
            'regionId' => 12,
            'regionCode' => 'CA',
            'region' => 'California',
            'postcode' => '90210',
            'city' => 'Beverly Hills',
            'street' => ['1 Rodeo Dr'],
        ]);
    }

    // ── destination-aware, multi-rate additive jurisdiction ─────────

    public function testCombinedStateAndLocalRateIsApplied(): void
    {
        // CA combined 6% state + 1.25% local = 7.25%
        $this->stubEngineRate(7.25);
        $this->stubRegularTaxClass();

        $result = $this->calculator->calculateForQuote(
            $this->makeQuote($this->usAddress()),
            $this->makeShippingAssignment($this->usAddress()),
            100.0,
            80.0, // base currency net
            4,
            1
        );

        $this->assertEqualsWithDelta(7.25, $result['tax_amount'], 1e-9);
        $this->assertEqualsWithDelta(5.8, $result['base_tax_amount'], 1e-9);
        $this->assertEqualsWithDelta(7.25, $result['tax_rate'], 1e-9);
    }

    public function testQuoteDetailsCarryClassKeyAndDestination(): void
    {
        $this->stubEngineRate(7.25);
        $this->stubRegularTaxClass();

        $this->calculator->calculateForQuote(
            $this->makeQuote($this->usAddress()),
            $this->makeShippingAssignment($this->usAddress()),
            100.0,
            100.0,
            4,
            1
        );

        // Two calls: quote currency + base currency (core's taxDetails /
        // baseTaxDetails pairing).
        $this->assertCount(2, $this->capturedQuoteDetails);

        $details = $this->capturedQuoteDetails[0];
        $item = $details->getItems()[0];

        // Item mirrors CommonTaxCollector::getShippingDataObject().
        $this->assertSame(SurchargeTaxCalculator::ITEM_CODE, $item->getCode());
        $this->assertSame(1, $item->getQuantity());
        $this->assertEqualsWithDelta(100.0, $item->getUnitPrice(), 1e-9);
        $this->assertFalse($item->getIsTaxIncluded());
        $this->assertSame(TaxClassKeyInterface::TYPE_ID, $item->getTaxClassKey()->getType());
        $this->assertSame(4, $item->getTaxClassKey()->getValue());

        // Destination context: mapped shipping address with full
        // (country, region, postcode) tuple for rate resolution.
        $shipping = $details->getShippingAddress();
        $this->assertSame('US', $shipping->getCountryId());
        $this->assertSame('90210', $shipping->getPostcode());
        $this->assertSame(12, $shipping->getRegion()->getRegionId());

        // Customer side of the rule: customer tax class + id from quote.
        $this->assertSame(TaxClassKeyInterface::TYPE_ID, $details->getCustomerTaxClassKey()->getType());
        $this->assertSame(3, $details->getCustomerTaxClassKey()->getValue());
        $this->assertSame(42, $details->getCustomerId());
    }

    // ── no matching tax rule for the destination ────────────────────

    public function testNoMatchingRuleYieldsZeroTax(): void
    {
        $this->stubEngineRate(0.0);

        $result = $this->calculator->calculateForQuote(
            $this->makeQuote($this->usAddress()),
            $this->makeShippingAssignment($this->usAddress()),
            100.0,
            100.0,
            4,
            1
        );

        $this->assertSame(0.0, $result['tax_amount']);
        $this->assertSame(0.0, $result['base_tax_amount']);
        $this->assertSame(0.0, $result['tax_rate']);
    }

    // ── always-zero provisioned class ───────────────────────────────

    public function testNoTaxClassYieldsZeroEverywhereWithoutWarning(): void
    {
        // The provisioned class ships with no Tax Rule attached, so the
        // engine resolves nothing regardless of destination.
        $this->stubEngineRate(0.0);
        $this->log->expects($this->never())->method('addErrorLog');

        $result = $this->calculator->calculateForQuote(
            $this->makeQuote($this->usAddress()),
            $this->makeShippingAssignment($this->usAddress()),
            100.0,
            100.0,
            99, // provisioned no-tax class id
            1
        );

        $this->assertSame(0.0, $result['tax_amount']);
    }

    public function testWarnsWhenNoTaxClassResolvesRealTax(): void
    {
        // A merchant attached a Tax Rule to the always-zero class:
        // the guarantee is broken — warn loudly, do not fail checkout.
        $this->stubEngineRate(25.0);
        $this->taxClassRepository->method('get')->with(99)->willReturn(
            new TaxClass(['className' => SurchargeTaxCalculator::NO_TAX_CLASS_NAME])
        );
        $this->log->expects($this->once())->method('addErrorLog')
            ->with($this->stringContains('Tax Rule'), $this->anything());

        $result = $this->calculator->calculateForQuote(
            $this->makeQuote($this->usAddress()),
            $this->makeShippingAssignment($this->usAddress()),
            100.0,
            100.0,
            99,
            1
        );

        // Engine result is still returned — internally consistent.
        $this->assertEqualsWithDelta(25.0, $result['tax_amount'], 1e-9);
    }

    public function testTaxedRegularClassDoesNotWarn(): void
    {
        $this->stubEngineRate(21.0);
        $this->taxClassRepository->method('get')->with(4)->willReturn(
            new TaxClass(['className' => 'Taxable Goods'])
        );
        $this->log->expects($this->never())->method('addErrorLog');

        $result = $this->calculator->calculateForQuote(
            $this->makeQuote($this->usAddress()),
            $this->makeShippingAssignment($this->usAddress()),
            100.0,
            100.0,
            4,
            1
        );

        $this->assertEqualsWithDelta(21.0, $result['tax_amount'], 1e-9);
    }

    public function testDeletedConfiguredClassLogsErrorButReturnsEngineResult(): void
    {
        $this->stubEngineRate(10.0);
        $this->taxClassRepository->method('get')->willThrowException(new NoSuchEntityException());
        $this->log->expects($this->once())->method('addErrorLog')
            ->with($this->stringContains('no longer exists'), $this->anything());

        $result = $this->calculator->calculateForQuote(
            $this->makeQuote($this->usAddress()),
            $this->makeShippingAssignment($this->usAddress()),
            100.0,
            100.0,
            7,
            1
        );

        $this->assertEqualsWithDelta(10.0, $result['tax_amount'], 1e-9);
    }
}
