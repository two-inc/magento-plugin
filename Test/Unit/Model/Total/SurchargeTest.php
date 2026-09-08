<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Model\Total;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\DataObject;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\App\ProductMetadataInterface;
use Magento\Framework\UrlInterface;
use Magento\Quote\Api\Data\ShippingAssignmentInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address\Total;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\Config\RepositoryInterface as ConfigRepository;
use Two\Gateway\Api\Log\RepositoryInterface as LogRepository;
use Magento\Tax\Model\Calculation as TaxCalculation;
use Two\Gateway\Api\BrandRegistryInterface;
use Two\Gateway\Model\Config\Repository as ConfigRepositoryModel;
use Two\Gateway\Model\Provenance;
use Two\Gateway\Model\Total\Surcharge;
use Two\Gateway\Service\Merchant\SettingsProvider;
use Two\Gateway\Service\Order\MerchantMinimumResolver;
use Two\Gateway\Service\Order\MinimumOrderGate;
use Two\Gateway\Service\Order\MinimumOrderProvider;
use Two\Gateway\Service\Order\SurchargeCalculator;
use Two\Gateway\Service\Order\SurchargeDisplay;
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

    /** @var MinimumOrderGate|\PHPUnit\Framework\MockObject\MockObject */
    private $minimumOrderGate;

    /** @var MinimumOrderProvider|\PHPUnit\Framework\MockObject\MockObject */
    private $minimumOrderProvider;

    /** @var MerchantMinimumResolver|\PHPUnit\Framework\MockObject\MockObject */
    private $merchantMinimumResolver;

    /** @var SurchargeDisplay|\PHPUnit\Framework\MockObject\MockObject */
    private $surchargeDisplay;

    /** @var LogRepository|\PHPUnit\Framework\MockObject\MockObject */
    private $logRepository;

    /** @var Surcharge */
    private $collector;

    protected function setUp(): void
    {
        $this->session = new CheckoutSession();
        $this->config = $this->createMock(ConfigRepository::class);
        $this->surchargeCalculator = $this->createMock(SurchargeCalculator::class);
        $this->taxCalculator = $this->createMock(SurchargeTaxCalculator::class);
        $this->minimumOrderGate = $this->createMock(MinimumOrderGate::class);
        // Existing tests exercise the tax-calculation branching; keep them
        // unaffected by defaulting the min-order gate to satisfied.
        $this->minimumOrderGate->method('isSatisfied')->willReturn(true);
        $this->minimumOrderProvider = $this->createMock(MinimumOrderProvider::class);
        $this->merchantMinimumResolver = $this->createMock(MerchantMinimumResolver::class);
        $this->surchargeDisplay = $this->createMock(SurchargeDisplay::class);
        $this->surchargeDisplay->method('forCart')->willReturn(SurchargeDisplay::EXCL);
        $this->surchargeDisplay->method('pick')
            ->willReturnCallback(static function (string $mode, float $net, float $tax): float {
                return $mode === SurchargeDisplay::EXCL ? $net : $net + $tax;
            });

        $this->logRepository = $this->createMock(LogRepository::class);

        $this->collector = new Surcharge(
            $this->session,
            $this->config,
            $this->surchargeCalculator,
            $this->taxCalculator,
            $this->logRepository,
            $this->minimumOrderGate,
            $this->minimumOrderProvider,
            $this->merchantMinimumResolver,
            $this->surchargeDisplay
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

            public function getStore()
            {
                return new class extends DataObject {
                    public function getBaseCurrencyCode()
                    {
                        return 'USD';
                    }
                };
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
        $this->surchargeCalculator->method('isSurchargeResolvable')->willReturn(true);
        $this->session->setTwoSelectedTerm(30);
        $this->surchargeCalculator->method('calculate')->willReturn([
            'amount' => 100.0,
            'tax_rate' => 21.0, // legacy flat rate from config, via pricing result
            'description' => 'Payment terms fee - 30 days',
        ]);
    }

    /**
     * TWO-25503: an unresolvable surcharge FX rate withdraws the payment
     * method (Two::isAvailable) — it must not error the totals collection,
     * which runs on every quote change while the method is still selected and
     * so made checkout unrecoverable.
     */
    public function testAnUnresolvableFxRateClearsTheSurchargeInsteadOfThrowing(): void
    {
        $this->config->method('getSurchargeType')->willReturn('percentage');
        $this->surchargeCalculator->method('isSurchargeResolvable')->willReturn(false);
        $this->surchargeCalculator->expects($this->never())->method('calculate');
        $this->session->setTwoSelectedTerm(30);
        $this->session->setTwoSurchargeAmount(100.0);

        $total = new Total(['grand_total' => 1000.0, 'base_grand_total' => 1000.0]);
        $this->collector->collect($this->makeQuote(), $this->makeShippingAssignment(), $total);

        $this->assertEqualsWithDelta(1000.0, $total->getGrandTotal(), 1e-9);
        $this->assertEqualsWithDelta(0.0, (float)$total->getData('two_surcharge_amount'), 1e-9);
        $this->assertEqualsWithDelta(0.0, (float)$this->session->getTwoSurchargeAmount(), 1e-9);
    }

    /**
     * A real config Repository, so the refusal comes from the production read
     * path rather than a mock, and the rows genuinely vary the stored value.
     */
    private function collectorReading(?string $storedSurchargeType): Surcharge
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(
            static function ($path) use ($storedSurchargeType) {
                return $path === 'payment/two_payment/surcharge_type' ? $storedSurchargeType : null;
            }
        );
        $brandRegistry = $this->createMock(BrandRegistryInterface::class);
        $brandRegistry->method('getCode')->willReturn('two_payment');

        $config = new ConfigRepositoryModel(
            $scopeConfig,
            $this->createMock(EncryptorInterface::class),
            $this->createMock(UrlInterface::class),
            $this->createMock(ProductMetadataInterface::class),
            $this->createMock(TaxCalculation::class),
            $brandRegistry,
            $this->createMock(SettingsProvider::class),
            $this->createMock(Provenance::class),
            // The SAME log mock the collector gets: the assertion below counts
            // every error line the whole read emits, so a per-collaborator mock
            // would make it vacuous.
            $this->logRepository
        );

        return new Surcharge(
            $this->session,
            $config,
            $this->surchargeCalculator,
            $this->taxCalculator,
            $this->logRepository,
            $this->minimumOrderGate,
            $this->minimumOrderProvider,
            $this->merchantMinimumResolver,
            $this->surchargeDisplay
        );
    }

    /**
     * Q54: a corrupt stored surcharge method zeroes THIS method's fee and logs
     * once. Raising out of the totals collector errors the whole checkout —
     * the failure mode TWO-25503 already fixed for an unresolvable FX rate.
     *
     * @dataProvider corruptStoredMethods
     */
    public function testACorruptStoredMethodZeroesTheSurchargeInsteadOfThrowing(
        string $stored,
        string $case
    ): void {
        $this->surchargeCalculator->expects($this->never())->method('calculate');
        $this->session->setTwoSelectedTerm(30);
        $this->session->setTwoSurchargeAmount(100.0);
        $logged = [];
        $this->logRepository->method('addErrorLog')->willReturnCallback(
            function ($type, $data) use (&$logged): void {
                $logged[] = $type . ' ' . (is_array($data) ? json_encode($data) : (string)$data);
            }
        );

        $total = new Total(['grand_total' => 1000.0, 'base_grand_total' => 1000.0]);
        $this->collectorReading($stored)->collect($this->makeQuote(), $this->makeShippingAssignment(), $total);

        $this->assertEqualsWithDelta(1000.0, $total->getGrandTotal(), 1e-9, $case);
        $this->assertEqualsWithDelta(0.0, (float)$total->getData('two_surcharge_amount'), 1e-9, $case);
        $this->assertEqualsWithDelta(0.0, (float)$this->session->getTwoSurchargeAmount(), 1e-9, $case);
        $this->assertCount(1, $logged, 'reported exactly once across the whole read: ' . $case);
        $this->assertStringContainsString($stored, $logged[0], 'the log names the stored value: ' . $case);
    }

    public function corruptStoredMethods(): array
    {
        return [
            ['wat', 'junk from a hand-edited row, config:set or an import'],
            ['PERCENTAGE', 'the right method in the wrong case'],
            ['0', 'a falsy value a truthiness check would have read as unset'],
        ];
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

    /**
     * A shipping-method change can drop the quote below the
     * minimum order value without ever deselecting `two_payment` on the
     * quote. The collector must clear the surcharge on that recollect
     * pass rather than keep reapplying it because the method code alone
     * still matches.
     */
    public function testSurchargeClearedWhenBelowMinimumOrderEvenWithPaymentMethodStillSelected(): void
    {
        $this->stubBaseline();
        $this->config->method('getSurchargeTaxClassId')->willReturn(null);
        $this->minimumOrderGate = $this->createMock(MinimumOrderGate::class);
        $this->minimumOrderGate->method('isSatisfied')->willReturn(false);

        $this->collector = new Surcharge(
            $this->session,
            $this->config,
            $this->surchargeCalculator,
            $this->taxCalculator,
            $this->createMock(LogRepository::class),
            $this->minimumOrderGate,
            $this->minimumOrderProvider,
            $this->merchantMinimumResolver,
            $this->surchargeDisplay
        );

        $this->session->setTwoSurchargeAmount(100.0);
        $this->session->setTwoSurchargeTax(21.0);

        $total = new Total(['grand_total' => 1000.0, 'base_grand_total' => 1000.0]);
        $this->collector->collect($this->makeQuote(), $this->makeShippingAssignment(), $total);

        $this->assertEqualsWithDelta(0.0, (float)$total->getData('two_surcharge_amount'), 1e-9);
        $this->assertEqualsWithDelta(1000.0, $total->getGrandTotal(), 1e-9);
        $this->assertEqualsWithDelta(0.0, (float)$this->session->getTwoSurchargeAmount(), 1e-9);
        $this->assertEqualsWithDelta(0.0, (float)$this->session->getTwoSurchargeTax(), 1e-9);
    }
    private function collectorDisplaying(string $mode): Surcharge
    {
        $display = $this->createMock(SurchargeDisplay::class);
        $display->method('forCart')->willReturn($mode);
        $display->method('pick')
            ->willReturnCallback(static function (string $m, float $net, float $tax): float {
                return $m === SurchargeDisplay::EXCL ? $net : $net + $tax;
            });

        return new Surcharge(
            $this->session,
            $this->config,
            $this->surchargeCalculator,
            $this->taxCalculator,
            $this->createMock(LogRepository::class),
            $this->minimumOrderGate,
            $this->minimumOrderProvider,
            $this->merchantMinimumResolver,
            $display
        );
    }

    /**
     * @dataProvider fetchSingleRowModes
     */
    public function testFetchSegmentValueFollowsTheStoreTaxDisplay(string $mode, float $expected): void
    {
        $this->session->setTwoSurchargeAmount(100.0);
        $this->session->setTwoSurchargeTax(21.0);
        $this->session->setTwoSurchargeDescription('Payment terms fee - 30 days');

        $fetched = $this->collectorDisplaying($mode)->fetch($this->makeQuote(), new Total());

        $this->assertSame('two_surcharge', $fetched['code']);
        $this->assertEqualsWithDelta($expected, (float)$fetched['value'], 1e-9);
        $this->assertSame('Payment terms fee - 30 days', (string)$fetched['title']);
        $this->assertSame(
            [],
            array_column($fetched, 'code'),
            'a single total must not satisfy TotalsReader::convert()\'s list predicate'
        );
    }

    /**
     * @return array<string, array{0: string, 1: float}>
     */
    public function fetchSingleRowModes(): array
    {
        return [
            'excl shows net' => [SurchargeDisplay::EXCL, 100.0],
            'incl shows net plus tax' => [SurchargeDisplay::INCL, 121.0],
        ];
    }

    public function testFetchEmitsPairedSegmentsInBothMode(): void
    {
        $this->session->setTwoSurchargeAmount(100.0);
        $this->session->setTwoSurchargeTax(21.0);
        $this->session->setTwoSurchargeDescription('Payment terms fee - 30 days');

        $fetched = $this->collectorDisplaying(SurchargeDisplay::BOTH)->fetch($this->makeQuote(), new Total());

        $this->assertCount(2, $fetched);
        $this->assertSame('two_surcharge', $fetched[0]['code']);
        $this->assertEqualsWithDelta(100.0, (float)$fetched[0]['value'], 1e-9);
        $this->assertSame('Payment terms fee - 30 days (Excl. Tax)', (string)$fetched[0]['title']);
        $this->assertSame('two_surcharge_incl', $fetched[1]['code']);
        $this->assertEqualsWithDelta(121.0, (float)$fetched[1]['value'], 1e-9);
        $this->assertSame('Payment terms fee - 30 days (Incl. Tax)', (string)$fetched[1]['title']);

        // The predicate Magento\Quote\Model\Quote\TotalsReader::convert()
        // uses to decide a collector returned a list of totals rather than
        // one: without it, "Both" collapses into a single mangled segment.
        $this->assertCount(2, array_column($fetched, 'code'));
    }

    public function testFetchEmitsNothingWithoutASurcharge(): void
    {
        $this->session->setTwoSurchargeAmount(0);

        $this->assertSame(
            [],
            $this->collectorDisplaying(SurchargeDisplay::INCL)->fetch($this->makeQuote(), new Total())
        );
    }
}
