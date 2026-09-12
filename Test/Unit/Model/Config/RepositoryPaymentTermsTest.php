<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Model\Config;

use Magento\Framework\App\Config\Initial;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ProductMetadataInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\UrlInterface;
use Magento\Framework\DataObject;
use Magento\Tax\Model\Calculation as TaxCalculation;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\BrandRegistryInterface;
use Two\Gateway\Model\Config\Repository;
use Two\Gateway\Model\Config\Source\SurchargeType as SurchargeTypeSource;
use Two\Gateway\Api\Log\RepositoryInterface as LogRepository;
use Two\Gateway\Model\Provenance;
use Two\Gateway\Service\Merchant\SettingsProvider;

class RepositoryPaymentTermsTest extends TestCase
{
    /** @var ScopeConfigInterface|\PHPUnit\Framework\MockObject\MockObject */
    private $scopeConfig;

    /** @var TaxCalculation|\PHPUnit\Framework\MockObject\MockObject */
    private $taxCalculation;

    /** @var SettingsProvider|\PHPUnit\Framework\MockObject\MockObject */
    private $settingsProvider;

    /** @var LogRepository|\PHPUnit\Framework\MockObject\MockObject */
    private $logRepository;

    /** @var Repository */
    private $repository;

    /**
     * Offered terms the stubbed merchant record resolves to. A resolvable
     * record is the baseline: with none, every buyer term reads as unoffered.
     *
     * @var int[]
     */
    private $offeredTerms = [7, 14, 21, 30, 37, 45, 60, 90];

    /** @var int|null The merchant record's own default term (`due_in_days`). */
    private $apiDefaultTerm = null;

    protected function setUp(): void
    {
        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $this->taxCalculation = $this->getMockBuilder(TaxCalculation::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getRateRequest', 'getRate'])
            ->getMock();

        $brandRegistry = $this->createMock(BrandRegistryInterface::class);
        $brandRegistry->method('getCode')->willReturn('two_payment');
        $brandRegistry->method('getProductName')->willReturn('Two');

        $this->settingsProvider = $this->createMock(SettingsProvider::class);
        $this->settingsProvider->method('getDefaultTerm')
            ->willReturnCallback(function (): ?int {
                return $this->apiDefaultTerm;
            });
        $this->settingsProvider->method('getAvailableTerms')
            ->willReturnCallback(function (): array {
                return $this->offeredTerms;
            });
        $this->logRepository = $this->createMock(LogRepository::class);

        $this->repository = new Repository(
            $this->scopeConfig,
            $this->createMock(EncryptorInterface::class),
            $this->createMock(UrlInterface::class),
            $this->createMock(ProductMetadataInterface::class),
            $this->taxCalculation,
            $brandRegistry,
            $this->settingsProvider,
            $this->createMock(Provenance::class),
            $this->logRepository
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

    /**
     * config:set bypasses the admin fields' save-time entitlement check, so
     * the read path intersects with the merchant record too (ABN-493).
     *
     * @param int[] $offered
     * @param int[] $expected
     * @dataProvider offeredIntersectionProvider
     */
    public function testGetAllBuyerTermsIntersectsWithTheOfferedSet(
        string $presets,
        string $custom,
        array $offered,
        array $expected,
        string $case
    ): void {
        $this->offeredTerms = $offered;
        $this->stubConfig([
            'payment/two_payment/payment_terms' => $presets,
            'payment/two_payment/payment_terms_duration_days' => $custom,
        ]);

        $this->assertSame($expected, $this->repository->getAllBuyerTerms(), $case);
    }

    public static function offeredIntersectionProvider(): array
    {
        return [
            ['14,30', '', [14, 30, 60], [14, 30], 'every stored term is offered'],
            ['14,30', '', [30], [30], 'a stored preset no longer offered is dropped'],
            ['14', '37', [14], [14], 'a stored custom day that is not offered is dropped'],
            ['14', '37', [14, 37], [14, 37], 'an offered custom day is kept'],
            ['7,37', '', [14, 30], [], 'nothing offered in common leaves no buyer terms'],
            ['14,30', '', [], [], 'an unresolvable merchant record offers no terms at all'],
        ];
    }

    // ── getDefaultPaymentTerm ────────────────────────────────────────

    /**
     * @param int[] $offered
     * @dataProvider defaultPaymentTermProvider
     */
    public function testGetDefaultPaymentTerm(
        string $storedDefault,
        string $presets,
        array $offered,
        ?int $apiDefault,
        ?int $expected,
        string $case
    ): void {
        $this->offeredTerms = $offered;
        $this->apiDefaultTerm = $apiDefault;
        $this->stubConfig([
            'payment/two_payment/default_payment_term' => $storedDefault,
            'payment/two_payment/payment_terms' => $presets,
            'payment/two_payment/payment_terms_duration_days' => '',
        ]);

        $this->assertSame($expected, $this->repository->getDefaultPaymentTerm(), $case);
    }

    public static function defaultPaymentTermProvider(): array
    {
        $allOffered = [7, 14, 21, 30, 37, 45, 60, 90];

        return [
            ['60', '30,60,90', $allOffered, null, 60, 'a stored default that is still offered wins'],
            ['30', '30,60,90', $allOffered, 90, 30, 'a stored default outranks the API default term'],
            ['14', '7,30,60', $allOffered, null, 30, 'a stored default that is not offered falls back to 30'],
            ['37', '14,30,37', [14, 30], null, 30, 'a stored default the merchant withdrew falls back to 30'],
            ['', '30,60,90', $allOffered, 60, 60, 'with no stored default the API default term is used'],
            ['60', '30,60,90', $allOffered, 14, 60, 'an API default term that is not offered is ignored'],
            ['', '60,90', $allOffered, null, 60, 'with neither default and no 30 offered the shortest term is used'],
            ['', '7,30', $allOffered, null, 30, '30 is preferred over a shorter offered term'],
            ['', '7,14', $allOffered, null, 7, 'without 30 offered the shortest term is used'],
            ['', '7,30', [7], null, 7, '30 configured but not offered by the merchant is not the default'],
            ['', '7,30,60', $allOffered, 60, 60, 'the API default term outranks both 30 and the shortest'],
            ['30', '90', $allOffered, null, 90, 'a single offered term wins over a stale stored default'],
            ['', '', $allOffered, null, null, 'no configured term leaves no default at all'],
            ['30', '30,60', [], null, null, 'an unresolvable merchant record leaves no default at all'],
        ];
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

    /**
     * @dataProvider surchargeLineDescriptions
     */
    public function testGetSurchargeLineDescription(
        string $brandCode,
        string $shippedDefault,
        ?string $shippedEom,
        ?string $stored,
        string $termsType,
        int $days,
        string $expected,
        string $case
    ): void {
        $repository = $this->repositoryForBrand($brandCode, $shippedDefault, [
            "payment/$brandCode/surcharge_line_description" => $stored,
            "payment/$brandCode/surcharge_line_description_eom" => $shippedEom,
            "payment/$brandCode/payment_terms_type" => $termsType,
        ]);

        $rendered = (string)__($repository->getSurchargeLineDescription(), $days);

        $this->assertSame($expected, $rendered, $case);
    }

    public static function surchargeLineDescriptions(): array
    {
        $shipped = 'Payment terms fee - %1 days';
        $shippedEom = 'Payment terms fee - %1 days from end of month';
        $custom = 'Extended terms fee - %1 days';

        // A second brand overlay, which ships its own wording for both bases.
        $brand = 'other_brand';
        $brandShipped = 'Brand fee - %1 days';
        $brandShippedEom = 'Brand fee - %1 days from end of month';

        return [
            ['two_payment', $shipped, $shippedEom, $shipped, 'standard', 14,
                'Payment terms fee - 14 days', 'standard, 14 days'],
            ['two_payment', $shipped, $shippedEom, $shipped, 'standard', 30,
                'Payment terms fee - 30 days', 'standard, 30 days'],
            ['two_payment', $shipped, $shippedEom, $shipped, 'standard', 90,
                'Payment terms fee - 90 days', 'standard, 90 days'],
            ['two_payment', $shipped, $shippedEom, $shipped, 'end_of_month', 30,
                'Payment terms fee - 30 days from end of month', 'EOM, 30 days'],
            ['two_payment', $shipped, $shippedEom, $shipped, 'end_of_month', 45,
                'Payment terms fee - 45 days from end of month', 'EOM, 45 days'],
            ['two_payment', $shipped, $shippedEom, $shipped, 'end_of_month', 60,
                'Payment terms fee - 60 days from end of month', 'EOM, 60 days'],
            ['two_payment', $shipped, $shippedEom, null, 'standard', 30,
                'Payment terms fee - 30 days', 'empty stored value, standard'],
            ['two_payment', $shipped, $shippedEom, null, 'end_of_month', 30,
                'Payment terms fee - 30 days from end of month', 'empty stored value, EOM'],
            ['two_payment', $shipped, $shippedEom, $custom, 'standard', 30,
                'Extended terms fee - 30 days', 'merchant template wins, standard'],
            ['two_payment', $shipped, $shippedEom, $custom, 'end_of_month', 30,
                'Extended terms fee - 30 days', 'merchant template wins, EOM'],
            [$brand, $brandShipped, $brandShippedEom, $brandShipped, 'standard', 30,
                'Brand fee - 30 days', 'brand default, standard'],
            [$brand, $brandShipped, $brandShippedEom, $brandShipped, 'end_of_month', 30,
                'Brand fee - 30 days from end of month', 'brand default, EOM'],
            [$brand, $brandShipped, $brandShippedEom, $custom, 'end_of_month', 30,
                'Extended terms fee - 30 days', 'merchant template wins over brand default, EOM'],
            [$brand, $brandShipped, null, $brandShipped, 'end_of_month', 30,
                'Brand fee - 30 days', 'brand ships no EOM wording, EOM'],
        ];
    }

    /**
     * @param array<string, string|null> $configMap
     */
    private function repositoryForBrand(string $brandCode, string $shippedDefault, array $configMap): Repository
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(
            function ($path) use ($configMap) {
                return $configMap[$path] ?? null;
            }
        );

        $brandRegistry = $this->createMock(BrandRegistryInterface::class);
        $brandRegistry->method('getCode')->willReturn($brandCode);
        $brandRegistry->method('getProductName')->willReturn('Two');

        $initialConfig = $this->createMock(Initial::class);
        $initialConfig->method('getData')->willReturn([
            'payment' => [$brandCode => ['surcharge_line_description' => $shippedDefault]],
        ]);

        return new Repository(
            $scopeConfig,
            $this->createMock(EncryptorInterface::class),
            $this->createMock(UrlInterface::class),
            $this->createMock(ProductMetadataInterface::class),
            $this->taxCalculation,
            $brandRegistry,
            $this->settingsProvider,
            $this->createMock(Provenance::class),
            $this->logRepository,
            null,
            null,
            $initialConfig
        );
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

    public function testGetSurchargeConfigDefaultsAmountsToZeroAndLimitToNull(): void
    {
        $this->stubConfig([]);
        $config = $this->repository->getSurchargeConfig(60);
        $this->assertEquals(0, $config['percentage']);
        $this->assertEquals(0, $config['fixed']);
        // `limit` defaults to NULL, not 0.0 — the two are not interchangeable:
        // null means "no cap" (uncapped percentage) while 0.0 is a real cap of
        // zero that suppresses the surcharge. The previous assertEquals(0.0, ...)
        // passed only because PHP's loose comparison treats null == 0.0.
        $this->assertNull($config['limit']);
    }

    /**
     * A stored zero is a REAL cap and must survive the read verbatim. A cap of
     * zero clamps the buyer fee to zero; absence is what means uncapped. This
     * is the assertion that stops the junk guard below being "simplified" into
     * a truthiness test, which would turn a zero cap into an uncapped
     * percentage — the overcharge TWO-25289 exists to close.
     */
    public function testGetSurchargeConfigRelaysAStoredZeroLimitVerbatim(): void
    {
        $this->stubConfig(['payment/two_payment/surcharge_30_limit' => '0']);
        $config = $this->repository->getSurchargeConfig(30);
        $this->assertNotNull($config['limit'], 'a stored zero is a cap, not an absent cap');
        $this->assertSame(0.0, $config['limit']);
    }

    /**
     * Junk in the stored limit reads as ABSENT, never as a cap.
     *
     * The admin grid refuses all of these on save, but the row can still be
     * written by a hand edit, `bin/magento config:set` or a config import.
     * Before the guard, `abc` cast to a hard cap of 0.0 and suppressed the fee
     * outright, and `-10` was relayed as a negative cap that the pricing
     * request is refused for.
     *
     * @dataProvider unusableStoredLimits
     * @param mixed $stored
     */
    public function testGetSurchargeConfigTreatsAnUnusableStoredLimitAsAbsent($stored): void
    {
        $this->stubConfig(['payment/two_payment/surcharge_30_limit' => $stored]);
        $this->assertNull($this->repository->getSurchargeConfig(30)['limit']);
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function unusableStoredLimits(): array
    {
        return [
            'non-numeric casts to a fee-suppressing zero' => ['abc'],
            'negative is refused upstream' => ['-10'],
            'empty string means no limit' => [''],
            'whitespace-only means no limit' => ['   '],
            'non-finite breaks serialisation' => ['1e400'],
        ];
    }

    /**
     * A non-scalar stored limit reads as absent WITHOUT attempting a string
     * cast. The same hand-edit and import routes that can store junk can store
     * an array, and casting one to string is a PHP warning rather than an
     * error — so the null result alone does not pin the guard (the cast yields
     * "Array", which is non-numeric and lands on null anyway). The assertion
     * that has to hold is that no diagnostic is raised at all.
     */
    public function testGetSurchargeConfigTreatsANonScalarStoredLimitAsAbsentWithoutADiagnostic(): void
    {
        $this->stubConfig(['payment/two_payment/surcharge_30_limit' => ['50']]);

        $raised = [];
        set_error_handler(
            static function ($severity, $message) use (&$raised) {
                $raised[] = $message;
                return true;
            }
        );
        try {
            $limit = $this->repository->getSurchargeConfig(30)['limit'];
        } finally {
            restore_error_handler();
        }

        $this->assertNull($limit);
        $this->assertSame([], $raised, 'a non-scalar limit must not be cast to string');
    }

    // ── isBuyerTermAvailable ─────────────────────────────────────────

    /**
     * @dataProvider buyerTermAvailability
     */
    public function testIsBuyerTermAvailable(int $termDays, bool $expected, string $case): void
    {
        $this->stubConfig([
            'payment/two_payment/payment_terms' => '14,30',
            'payment/two_payment/payment_terms_duration_days' => '21',
        ]);

        $this->assertSame($expected, $this->repository->isBuyerTermAvailable($termDays), $case);
    }

    public function buyerTermAvailability(): array
    {
        return [
            [14, true, 'a term from the multiselect'],
            [21, true, 'the custom duration'],
            [90, false, 'a term the merchant does not offer'],
            [0, false, 'no term at all'],
        ];
    }

    // ── getDefaultShippingTaxRate ────────────────────────────────────

    /**
     * @dataProvider shippingTaxRateFallbacks
     */
    public function testGetDefaultShippingTaxRate($stored, ?float $expected, string $case): void
    {
        $this->stubConfig(['payment/two_payment/default_shipping_tax_rate' => $stored]);

        $this->assertSame($expected, $this->repository->getDefaultShippingTaxRate(), $case);
    }

    public function shippingTaxRateFallbacks(): array
    {
        return [
            ['25', 25.0, 'a configured rate'],
            ['0', 0.0, 'a declared zero rate is a declaration, not an absence'],
            [null, null, 'never configured'],
            ['', null, 'the empty initial config node'],
            ['abc', null, 'junk from a hand-edited row or config:set'],
            ['-10', null, 'a negative rate is not a rate'],
            [['25'], null, 'a non-scalar value'],
        ];
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

    // ── getSurchargeType ────────────────────────────────────────────

    /**
     * @dataProvider acceptedSurchargeTypes
     * @param mixed $stored
     */
    public function testGetSurchargeTypeAcceptsTheKnownSet($stored, string $expected, string $case): void
    {
        $this->stubConfig(['payment/two_payment/surcharge_type' => $stored]);
        $this->assertSame($expected, $this->repository->getSurchargeType(), $case);
    }

    public function acceptedSurchargeTypes(): array
    {
        return [
            ['none', 'none', 'explicitly disabled'],
            ['percentage', 'percentage', 'percentage'],
            ['fixed', 'fixed', 'fixed fee'],
            ['fixed_and_percentage', 'fixed_and_percentage', 'fixed fee and percentage'],
            [null, 'none', 'never configured'],
            ['', 'none', 'the empty initial config node'],
        ];
    }

    /**
     * @dataProvider refusedSurchargeTypes
     */
    public function testGetSurchargeTypeRefusesAnythingElse(string $stored, string $case): void
    {
        $this->stubConfig(['payment/two_payment/surcharge_type' => $stored]);
        try {
            $this->repository->getSurchargeType();
            $this->fail('expected a refusal: ' . $case);
        } catch (LocalizedException $e) {
            // Generic on purpose: this reaches the BUYER at placement, so it
            // must not leak the merchant's stored value or the enum keys.
            $this->assertSame('Invoice purchase with Two is not available for this order.', $e->getMessage(), $case);
            $this->assertStringNotContainsString($stored, $e->getMessage(), 'no stored value: ' . $case);
            foreach (SurchargeTypeSource::KNOWN as $known) {
                $this->assertStringNotContainsString($known, $e->getMessage(), 'no enum keys: ' . $case);
            }
        }
    }

    /**
     * getSurchargeType() is read once per isAvailable() and once per
     * collectTotals(), so reporting on every read filled the log with the same
     * line. Reported once per offending value per request; a second distinct
     * value still speaks.
     */
    public function testAnUnrecognisedMethodIsReportedOncePerRequest(): void
    {
        $stored = 'wat';
        $this->scopeConfig->method('getValue')->willReturnCallback(
            function ($path) use (&$stored) {
                return $path === 'payment/two_payment/surcharge_type' ? $stored : null;
            }
        );
        $logged = [];
        $this->logRepository->method('addErrorLog')->willReturnCallback(
            function ($type, $data) use (&$logged): void {
                $logged[] = is_array($data) ? (string)($data['value'] ?? '') : (string)$data;
            }
        );

        foreach ([1, 2, 3] as $ignored) {
            try {
                $this->repository->getSurchargeType();
            } catch (LocalizedException $e) {
                // Every read still refuses; only the reporting is deduplicated.
                $this->assertSame('Invoice purchase with Two is not available for this order.', $e->getMessage());
            }
        }
        $this->assertSame(['wat'], $logged, 'three reads, one report');

        $stored = 'also_wrong';
        try {
            $this->repository->getSurchargeType();
        } catch (LocalizedException $e) {
            $this->assertSame('Invoice purchase with Two is not available for this order.', $e->getMessage());
        }
        $this->assertSame(['wat', 'also_wrong'], $logged, 'the log, not the buyer, carries the value');
    }

    public function refusedSurchargeTypes(): array
    {
        return [
            ['wat', 'junk from a hand-edited row, config:set or an import'],
            ['PERCENTAGE', 'the right method in the wrong case is still not a method'],
            ['percentage_and_fixed', 'a plausible-looking method that does not exist'],
            ['0', 'a falsy value that is not the empty node'],
        ];
    }
}
