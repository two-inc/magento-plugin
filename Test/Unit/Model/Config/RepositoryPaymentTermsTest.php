<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Model\Config;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ProductMetadataInterface;
use Magento\Framework\App\State;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\UrlInterface;
use Magento\Framework\DataObject;
use Magento\Tax\Model\Calculation as TaxCalculation;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Model\Config\Repository;

class RepositoryPaymentTermsTest extends TestCase
{
    /** @var ScopeConfigInterface|\PHPUnit\Framework\MockObject\MockObject */
    private $scopeConfig;

    /** @var TaxCalculation|\PHPUnit\Framework\MockObject\MockObject */
    private $taxCalculation;

    /** @var Repository */
    private $repository;

    protected function setUp(): void
    {
        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $this->taxCalculation = $this->getMockBuilder(TaxCalculation::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getRateRequest', 'getRate'])
            ->getMock();

        $this->repository = new Repository(
            $this->scopeConfig,
            $this->createMock(EncryptorInterface::class),
            $this->createMock(UrlInterface::class),
            $this->createMock(ProductMetadataInterface::class),
            $this->createMock(State::class),
            $this->taxCalculation
        );
    }

    private function stubConfig(array $map): void
    {
        $this->scopeConfig->method('getValue')->willReturnCallback(
            function ($path) use ($map) {
                return $map[$path] ?? null;
            }
        );
    }

    private function stubFlags(array $map): void
    {
        $this->scopeConfig->method('isSetFlag')->willReturnCallback(
            function ($path) use ($map) {
                return $map[$path] ?? false;
            }
        );
    }

    // ── getPaymentTerms ──────────────────────────────────────────────

    public function testGetPaymentTermsReturnsParsedArray(): void
    {
        $this->stubConfig(['payment/two_payment/payment_terms' => '14,30,60']);
        $this->assertEquals([14, 30, 60], $this->repository->getPaymentTerms());
    }

    public function testGetPaymentTermsReturnsEmptyWhenNotSet(): void
    {
        $this->stubConfig([]);
        $this->assertEquals([], $this->repository->getPaymentTerms());
    }

    public function testGetPaymentTermsSingleValue(): void
    {
        $this->stubConfig(['payment/two_payment/payment_terms' => '90']);
        $this->assertEquals([90], $this->repository->getPaymentTerms());
    }

    // ── getPaymentTermsDurationDays ──────────────────────────────────

    public function testCustomDurationReturnsZeroWhenEmpty(): void
    {
        $this->stubConfig(['payment/two_payment/payment_terms_duration_days' => '']);
        $this->assertEquals(0, $this->repository->getPaymentTermsDurationDays());
    }

    public function testCustomDurationReturnsValue(): void
    {
        $this->stubConfig(['payment/two_payment/payment_terms_duration_days' => '21']);
        $this->assertEquals(21, $this->repository->getPaymentTermsDurationDays());
    }

    // ── getAllBuyerTerms ─────────────────────────────────────────────

    public function testGetAllBuyerTermsMergesMultiselectAndCustom(): void
    {
        $this->stubConfig([
            'payment/two_payment/payment_terms' => '14,60',
            'payment/two_payment/payment_terms_duration_days' => '21',
        ]);
        $this->assertEquals([14, 21, 60], $this->repository->getAllBuyerTerms());
    }

    public function testGetAllBuyerTermsDeduplicates(): void
    {
        $this->stubConfig([
            'payment/two_payment/payment_terms' => '30,60',
            'payment/two_payment/payment_terms_duration_days' => '30',
        ]);
        $this->assertEquals([30, 60], $this->repository->getAllBuyerTerms());
    }

    public function testGetAllBuyerTermsCustomOnly(): void
    {
        $this->stubConfig([
            'payment/two_payment/payment_terms' => '',
            'payment/two_payment/payment_terms_duration_days' => '45',
        ]);
        $this->assertEquals([45], $this->repository->getAllBuyerTerms());
    }

    public function testGetAllBuyerTermsMultiselectOnly(): void
    {
        $this->stubConfig([
            'payment/two_payment/payment_terms' => '30,90',
            'payment/two_payment/payment_terms_duration_days' => '',
        ]);
        $this->assertEquals([30, 90], $this->repository->getAllBuyerTerms());
    }

    public function testGetAllBuyerTermsReturnsEmptyWhenNothingConfigured(): void
    {
        $this->stubConfig([
            'payment/two_payment/payment_terms' => '',
            'payment/two_payment/payment_terms_duration_days' => '',
        ]);
        $this->assertEquals([], $this->repository->getAllBuyerTerms());
    }

    // ── getDefaultPaymentTerm ────────────────────────────────────────

    public function testGetDefaultPaymentTermReturnsConfiguredValue(): void
    {
        $this->stubConfig([
            'payment/two_payment/default_payment_term' => '60',
            'payment/two_payment/payment_terms' => '30,60,90',
            'payment/two_payment/payment_terms_duration_days' => '',
        ]);
        $this->assertEquals(60, $this->repository->getDefaultPaymentTerm());
    }

    public function testGetDefaultPaymentTermFallsBackToLowest(): void
    {
        $this->stubConfig([
            'payment/two_payment/default_payment_term' => '',
            'payment/two_payment/payment_terms' => '60,90',
            'payment/two_payment/payment_terms_duration_days' => '',
        ]);
        $this->assertEquals(60, $this->repository->getDefaultPaymentTerm());
    }

    public function testGetDefaultPaymentTermFallsBackTo30WhenNoTerms(): void
    {
        $this->stubConfig([
            'payment/two_payment/default_payment_term' => '',
            'payment/two_payment/payment_terms' => '',
            'payment/two_payment/payment_terms_duration_days' => '',
        ]);
        $this->assertEquals(30, $this->repository->getDefaultPaymentTerm());
    }

    // ── getSurchargeType ─────────────────────────────────────────────

    public function testGetSurchargeTypeReturnsNoneByDefault(): void
    {
        $this->stubConfig([]);
        $this->assertEquals('none', $this->repository->getSurchargeType());
    }

    public function testGetSurchargeTypeReturnsConfiguredValue(): void
    {
        $this->stubConfig(['payment/two_payment/surcharge_type' => 'percentage']);
        $this->assertEquals('percentage', $this->repository->getSurchargeType());
    }

    // ── isSurchargeDifferential ──────────────────────────────────────

    public function testIsSurchargeDifferentialReturnsFalseByDefault(): void
    {
        $this->stubFlags([]);
        $this->assertFalse($this->repository->isSurchargeDifferential());
    }

    public function testIsSurchargeDifferentialReturnsTrue(): void
    {
        $this->stubFlags(['payment/two_payment/surcharge_differential' => true]);
        $this->assertTrue($this->repository->isSurchargeDifferential());
    }

    // ── getSurchargeLineDescription ─────────────────────────────────

    public function testGetSurchargeLineDescriptionDefault(): void
    {
        $this->stubConfig([]);
        $this->assertEquals('Payment terms fee', $this->repository->getSurchargeLineDescription());
    }

    public function testGetSurchargeLineDescriptionCustom(): void
    {
        $this->stubConfig(['payment/two_payment/surcharge_line_description' => 'Extended terms fee']);
        $this->assertEquals('Extended terms fee', $this->repository->getSurchargeLineDescription());
    }

    // ── getSurchargeTaxRate ──────────────────────────────────────────

    public function testGetSurchargeTaxRateReturnsExplicitValue(): void
    {
        $this->stubConfig(['payment/two_payment/surcharge_tax_rate' => '21']);
        $this->assertEquals(21.0, $this->repository->getSurchargeTaxRate());
    }

    public function testGetSurchargeTaxRateExplicitZeroMeansTaxExempt(): void
    {
        $this->stubConfig(['payment/two_payment/surcharge_tax_rate' => '0']);
        $this->assertEquals(0.0, $this->repository->getSurchargeTaxRate());
    }

    public function testGetSurchargeTaxRateFallsBackToDefaultRate(): void
    {
        $this->stubConfig([
            'payment/two_payment/surcharge_tax_rate' => null,
            'tax/classes/default_product_tax_class' => '2',
        ]);
        $rateRequest = new DataObject();
        $this->taxCalculation->method('getRateRequest')
            ->with(null, null, null, null)
            ->willReturn($rateRequest);
        $this->taxCalculation->method('getRate')
            ->with($rateRequest)
            ->willReturn(25.0);

        $this->assertEquals(25.0, $this->repository->getSurchargeTaxRate());
    }

    public function testGetSurchargeTaxRateReturnsZeroWhenNoTaxRulesConfigured(): void
    {
        $this->stubConfig([
            'payment/two_payment/surcharge_tax_rate' => null,
            'tax/classes/default_product_tax_class' => null,
        ]);
        $this->assertEquals(0.0, $this->repository->getSurchargeTaxRate());
    }

    // ── getSurchargeConfig ──────────────────────────────────────────

    public function testGetSurchargeConfigReturnsPerTermValues(): void
    {
        $this->stubConfig([
            'payment/two_payment/surcharge_30_percentage' => '50',
            'payment/two_payment/surcharge_30_fixed' => '10',
            'payment/two_payment/surcharge_30_limit' => '25.50',
        ]);

        $config = $this->repository->getSurchargeConfig(30);
        $this->assertEquals(50, $config['percentage']);
        $this->assertEquals(10, $config['fixed']);
        $this->assertEquals(25.50, $config['limit']);
    }

    public function testGetSurchargeConfigDefaultsToZero(): void
    {
        $this->stubConfig([]);
        $config = $this->repository->getSurchargeConfig(60);
        $this->assertEquals(0, $config['percentage']);
        $this->assertEquals(0, $config['fixed']);
        $this->assertEquals(0.0, $config['limit']);
    }

    // ── getPaymentTermsType (retained) ──────────────────────────────

    public function testGetPaymentTermsTypeDefaultsToStandard(): void
    {
        $this->stubConfig([]);
        $this->assertEquals('standard', $this->repository->getPaymentTermsType());
    }

    public function testGetPaymentTermsTypeReturnsEndOfMonth(): void
    {
        $this->stubConfig(['payment/two_payment/payment_terms_type' => 'end_of_month']);
        $this->assertEquals('end_of_month', $this->repository->getPaymentTermsType());
    }
}
