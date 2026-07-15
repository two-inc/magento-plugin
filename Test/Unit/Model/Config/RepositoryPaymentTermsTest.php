<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Model\Config;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ProductMetadataInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\UrlInterface;
use Magento\Framework\DataObject;
use Magento\Tax\Model\Calculation as TaxCalculation;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\BrandRegistryInterface;
use Two\Gateway\Model\Config\Repository;
use Two\Gateway\Service\Merchant\SettingsProvider;

class RepositoryPaymentTermsTest extends TestCase
{
    /** @var ScopeConfigInterface|\PHPUnit\Framework\MockObject\MockObject */
    private $scopeConfig;

    /** @var TaxCalculation|\PHPUnit\Framework\MockObject\MockObject */
    private $taxCalculation;

    /** @var SettingsProvider|\PHPUnit\Framework\MockObject\MockObject */
    private $settingsProvider;

    /** @var Repository */
    private $repository;

    protected function setUp(): void
    {
        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $this->taxCalculation = $this->getMockBuilder(TaxCalculation::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getRateRequest', 'getRate'])
            ->getMock();

        $brandRegistry = $this->createMock(BrandRegistryInterface::class);
        $brandRegistry->method('getCode')->willReturn('two_payment');

        // Unstubbed getDefaultTerm() returns null, so the default-term
        // tests below exercise the config-based fallback; the API-default
        // cases stub it explicitly.
        $this->settingsProvider = $this->createMock(SettingsProvider::class);

        $this->repository = new Repository(
            $this->scopeConfig,
            $this->createMock(EncryptorInterface::class),
            $this->createMock(UrlInterface::class),
            $this->createMock(ProductMetadataInterface::class),
            $this->taxCalculation,
            $brandRegistry,
            $this->settingsProvider
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

    public function testGetDefaultPaymentTermPreselectsSingleAvailableTermDespiteStaleDefault(): void
    {
        // ABN-439: with a single available term, that term must always be the
        // default (and therefore preselected), even if a stale
        // default_payment_term points at a term that's no longer available.
        $this->stubConfig([
            'payment/two_payment/default_payment_term' => '30', // stale, not available
            'payment/two_payment/payment_terms' => '90',        // only 90 available
            'payment/two_payment/payment_terms_duration_days' => '',
        ]);
        $this->assertEquals(90, $this->repository->getDefaultPaymentTerm());
    }

    public function testGetDefaultPaymentTermIgnoresDefaultOutsideAvailableTerms(): void
    {
        // A configured default that isn't among the available terms falls back
        // to the lowest available term rather than returning an unselectable one.
        $this->stubConfig([
            'payment/two_payment/default_payment_term' => '14', // not available
            'payment/two_payment/payment_terms' => '30,60,90',
            'payment/two_payment/payment_terms_duration_days' => '',
        ]);
        $this->assertEquals(30, $this->repository->getDefaultPaymentTerm());
    }

    public function testGetDefaultPaymentTermAdminChoiceWinsOverApi(): void
    {
        // An explicit admin-configured default (when offered) is the
        // admin's own choice and must NOT be silently overridden by the
        // merchant's due_in_days (TWO-24859). The API default only seeds
        // the field when the admin hasn't chosen — see the unset test.
        $this->settingsProvider->method('getDefaultTerm')->willReturn(90);
        $this->stubConfig([
            'payment/two_payment/default_payment_term' => '30',
            'payment/two_payment/payment_terms' => '30,60,90',
            'payment/two_payment/payment_terms_duration_days' => '',
        ]);
        $this->assertEquals(30, $this->repository->getDefaultPaymentTerm());
    }

    public function testGetDefaultPaymentTermUsesApiDefaultWhenAdminUnset(): void
    {
        // No explicit admin choice (config.xml carries no static default):
        // fall back to the merchant's due_in_days when it is an offered
        // term, so a never-touched install matches what the admin field
        // pre-selects.
        $this->settingsProvider->method('getDefaultTerm')->willReturn(60);
        $this->stubConfig([
            'payment/two_payment/default_payment_term' => '',
            'payment/two_payment/payment_terms' => '30,60,90',
            'payment/two_payment/payment_terms_duration_days' => '',
        ]);
        $this->assertEquals(60, $this->repository->getDefaultPaymentTerm());
    }

    public function testGetDefaultPaymentTermIgnoresApiTermOutsideOfferedTerms(): void
    {
        // due_in_days is not guaranteed to be an offered term; when it
        // isn't, fall through (here to the admin-configured default).
        $this->settingsProvider->method('getDefaultTerm')->willReturn(14);
        $this->stubConfig([
            'payment/two_payment/default_payment_term' => '60',
            'payment/two_payment/payment_terms' => '30,60,90',
            'payment/two_payment/payment_terms_duration_days' => '',
        ]);
        $this->assertEquals(60, $this->repository->getDefaultPaymentTerm());
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
        $this->assertEquals('Payment terms fee - %1 days', $this->repository->getSurchargeLineDescription());
    }

    public function testGetSurchargeLineDescriptionCustom(): void
    {
        $this->stubConfig(['payment/two_payment/surcharge_line_description' => 'Extended terms fee']);
        $this->assertEquals('Extended terms fee', $this->repository->getSurchargeLineDescription());
    }

    // ── getCustomSurchargeTaxRate (deprecated flat rate) ─────────────

    public function testGetCustomSurchargeTaxRateReturnsExplicitValue(): void
    {
        $this->stubConfig(['payment/two_payment/surcharge_tax_rate' => '21']);
        $this->assertEquals(21.0, $this->repository->getCustomSurchargeTaxRate());
    }

    public function testGetCustomSurchargeTaxRateExplicitZeroMeansTaxExempt(): void
    {
        $this->stubConfig(['payment/two_payment/surcharge_tax_rate' => '0']);
        $this->assertEquals(0.0, $this->repository->getCustomSurchargeTaxRate());
    }

    public function testGetCustomSurchargeTaxRateFallsBackToDefaultRate(): void
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

        $this->assertEquals(25.0, $this->repository->getCustomSurchargeTaxRate());
    }

    public function testGetCustomSurchargeTaxRateReturnsZeroWhenNoTaxRulesConfigured(): void
    {
        $this->stubConfig([
            'payment/two_payment/surcharge_tax_rate' => null,
            'tax/classes/default_product_tax_class' => null,
        ]);
        $this->assertEquals(0.0, $this->repository->getCustomSurchargeTaxRate());
    }

    // ── hasCustomSurchargeTaxRate ────────────────────────────────────

    public function testHasCustomSurchargeTaxRateTrueForRealValue(): void
    {
        $this->stubConfig(['payment/two_payment/surcharge_tax_rate' => '21.5']);
        $this->assertTrue($this->repository->hasCustomSurchargeTaxRate());
    }

    public function testHasCustomSurchargeTaxRateTrueForConfiguredZero(): void
    {
        // Falsy-zero guard: a configured rate of 0 is still a real value.
        $this->stubConfig(['payment/two_payment/surcharge_tax_rate' => '0']);
        $this->assertTrue($this->repository->hasCustomSurchargeTaxRate());
    }

    public function testHasCustomSurchargeTaxRateFalseWhenUnset(): void
    {
        $this->stubConfig(['payment/two_payment/surcharge_tax_rate' => null]);
        $this->assertFalse($this->repository->hasCustomSurchargeTaxRate());
    }

    public function testHasCustomSurchargeTaxRateFalseForInitialEmptyString(): void
    {
        // etc/config.xml ships an empty <surcharge_tax_rate/> node, so an
        // untouched install reads '' (not null) — that is NOT a real value.
        $this->stubConfig(['payment/two_payment/surcharge_tax_rate' => '']);
        $this->assertFalse($this->repository->hasCustomSurchargeTaxRate());
    }

    // ── getSurchargeTaxClassId ──────────────────────────────────────

    public function testGetSurchargeTaxClassIdReturnsConfiguredClass(): void
    {
        $this->stubConfig(['payment/two_payment/surcharge_tax_class' => '4']);
        $this->assertSame(4, $this->repository->getSurchargeTaxClassId());
    }

    public function testGetSurchargeTaxClassIdZeroIsValidNoneSelection(): void
    {
        $this->stubConfig(['payment/two_payment/surcharge_tax_class' => '0']);
        $this->assertSame(0, $this->repository->getSurchargeTaxClassId());
    }

    public function testGetSurchargeTaxClassIdNullWhenUnset(): void
    {
        $this->stubConfig([]);
        $this->assertNull($this->repository->getSurchargeTaxClassId());
    }

    public function testGetSurchargeTaxClassIdNullOnUnselectedPlaceholder(): void
    {
        // The source model's placeholder option saves an empty string.
        $this->stubConfig(['payment/two_payment/surcharge_tax_class' => '']);
        $this->assertNull($this->repository->getSurchargeTaxClassId());
    }

    public function testGetSurchargeTaxClassIdNullOnDeprecatedCustomTreatment(): void
    {
        // "custom" routes to the deprecated flat-rate path — and must
        // NEVER int-cast to 0, which would silently mean "None"/untaxed.
        $this->stubConfig(['payment/two_payment/surcharge_tax_class' => 'custom']);
        $this->assertNull($this->repository->getSurchargeTaxClassId());
    }

    public function testGetSurchargeTaxClassIdNullOnUnknownNonNumericToken(): void
    {
        $this->stubConfig(['payment/two_payment/surcharge_tax_class' => 'garbage']);
        $this->assertNull($this->repository->getSurchargeTaxClassId());
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
