<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Model\Config\Backend;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\Context;
use Magento\Framework\Registry;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Model\Config\Backend\SurchargeTaxClass;

/**
 * Tests the never-auto-default enforcement for the surcharge tax
 * treatment selector: while surcharges are enabled the save must be
 * rejected until a treatment is explicitly selected, and the
 * deprecated "custom" treatment is only accepted when a legacy flat
 * rate genuinely exists (0 counts as existing — falsy-zero guard).
 * Core "None" (class id 0) is refused unless the scope being saved
 * already stores it.
 */
class SurchargeTaxClassTest extends TestCase
{
    /** @var ScopeConfigInterface|\PHPUnit\Framework\MockObject\MockObject */
    private $scopeConfig;

    protected function setUp(): void
    {
        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);
    }

    private function buildModel(array $data): SurchargeTaxClass
    {
        return new SurchargeTaxClass(
            $this->getMockBuilder(Context::class)->disableOriginalConstructor()->getMock(),
            $this->getMockBuilder(Registry::class)->disableOriginalConstructor()->getMock(),
            $this->scopeConfig,
            $this->createMock(TypeListInterface::class),
            null,
            null,
            $data
        );
    }

    /** @var array<int, array{0: string, 1: string|null, 2: mixed}> path/scope/scopeId of every read */
    private $configReads = [];

    private function stubStoredConfig(array $map): void
    {
        $this->configReads = [];
        $this->scopeConfig->method('getValue')->willReturnCallback(
            function ($path, $scope = null, $scopeId = null) use ($map) {
                $this->configReads[] = [$path, $scope, $scopeId];
                return $map[$path] ?? null;
            }
        );
    }

    public function testEmptyValueWithSurchargeEnabledInSameSaveIsRejected(): void
    {
        $model = $this->buildModel([
            'value' => '',
            'path' => 'payment/two_payment/surcharge_tax_class',
            'scope' => 'default',
            'fieldset_data' => ['surcharge_type' => 'percentage'],
        ]);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Please select a Surcharge Tax Treatment');
        $model->beforeSave();
    }

    public function testEmptyValueWithSurchargeEnabledIsAcceptedWhenLegacyRateExists(): void
    {
        // Legacy merchants configured before the selector existed HAVE made a
        // choice; the guard must not lock them out of the section.
        $this->stubStoredConfig(['payment/two_payment/surcharge_tax_rate' => '21']);
        $model = $this->buildModel([
            'value' => '',
            'path' => 'payment/two_payment/surcharge_tax_class',
            'scope' => 'default',
            'fieldset_data' => ['surcharge_type' => 'percentage'],
        ]);

        $this->assertSame($model, $model->beforeSave());
    }

    public function testEmptyValueWithSurchargeEnabledIsAcceptedWhenLegacyRateIsZero(): void
    {
        // Falsy-zero guard: a configured rate of "0" is a real value.
        $this->stubStoredConfig(['payment/two_payment/surcharge_tax_rate' => '0']);
        $model = $this->buildModel([
            'value' => '',
            'path' => 'payment/two_payment/surcharge_tax_class',
            'scope' => 'default',
            'fieldset_data' => ['surcharge_type' => 'percentage'],
        ]);

        $this->assertSame($model, $model->beforeSave());
    }

    public function testExplicitBlankIsNotSatisfiedByStoredTaxClass(): void
    {
        // Clearing the selector must be rejected even though the stored value
        // is still populated — the field's own submitted value wins.
        $this->stubStoredConfig(['payment/two_payment/surcharge_tax_class' => '4']);
        $model = $this->buildModel([
            'value' => '',
            'path' => 'payment/two_payment/surcharge_tax_class',
            'scope' => 'default',
            'fieldset_data' => ['surcharge_type' => 'percentage'],
        ]);

        $this->expectException(LocalizedException::class);
        $model->beforeSave();
    }

    public function testEmptyValueWithSurchargeDisabledInSameSaveIsAccepted(): void
    {
        $model = $this->buildModel([
            'value' => '',
            'path' => 'payment/two_payment/surcharge_tax_class',
            'scope' => 'default',
            'fieldset_data' => ['surcharge_type' => 'none'],
        ]);

        $this->assertSame($model, $model->beforeSave());
    }

    public function testEmptyValueFallsBackToStoredSurchargeTypeWhenNotPosted(): void
    {
        $this->stubStoredConfig(['payment/two_payment/surcharge_type' => 'fixed']);
        $model = $this->buildModel([
            'value' => '',
            'path' => 'payment/two_payment/surcharge_tax_class',
            'scope' => 'default',
        ]);

        $this->expectException(LocalizedException::class);
        $model->beforeSave();
    }

    public function testEmptyValueWithNoSurchargeConfiguredAnywhereIsAccepted(): void
    {
        $this->stubStoredConfig([]);
        $model = $this->buildModel([
            'value' => '',
            'path' => 'payment/two_payment/surcharge_tax_class',
            'scope' => 'default',
        ]);

        $this->assertSame($model, $model->beforeSave());
    }

    public function testCustomIsAcceptedWhenLegacyRateExists(): void
    {
        $this->stubStoredConfig(['payment/two_payment/surcharge_tax_rate' => '21.5']);
        $model = $this->buildModel([
            'value' => 'custom',
            'path' => 'payment/two_payment/surcharge_tax_class',
            'scope' => 'default',
        ]);

        $this->assertSame($model, $model->beforeSave());
    }

    public function testCustomIsAcceptedWhenLegacyRateIsConfiguredZero(): void
    {
        // Falsy-zero guard: a configured rate of "0" is a real value.
        $this->stubStoredConfig(['payment/two_payment/surcharge_tax_rate' => '0']);
        $model = $this->buildModel([
            'value' => 'custom',
            'path' => 'payment/two_payment/surcharge_tax_class',
            'scope' => 'default',
        ]);

        $this->assertSame($model, $model->beforeSave());
    }

    public function testCustomIsRejectedWhenNoLegacyRateExists(): void
    {
        $this->stubStoredConfig(['payment/two_payment/surcharge_tax_rate' => null]);
        $model = $this->buildModel([
            'value' => 'custom',
            'path' => 'payment/two_payment/surcharge_tax_class',
            'scope' => 'default',
        ]);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('deprecated');
        $model->beforeSave();
    }

    public function testCustomIsRejectedWhenLegacyRateIsInitialEmptyString(): void
    {
        // etc/config.xml ships an empty <surcharge_tax_rate/> node, so an
        // untouched install reads '' — that is NOT a pre-existing rate.
        $this->stubStoredConfig(['payment/two_payment/surcharge_tax_rate' => '']);
        $model = $this->buildModel([
            'value' => 'custom',
            'path' => 'payment/two_payment/surcharge_tax_class',
            'scope' => 'default',
        ]);

        $this->expectException(LocalizedException::class);
        $model->beforeSave();
    }

    public function testTaxClassSelectionIsAcceptedWithSurchargeEnabled(): void
    {
        $model = $this->buildModel([
            'value' => '4',
            'path' => 'payment/two_payment/surcharge_tax_class',
            'scope' => 'default',
            'fieldset_data' => ['surcharge_type' => 'percentage'],
        ]);

        $this->assertSame($model, $model->beforeSave());
    }

    /**
     * The suppressed "None" option is a UI rule; without a save-time check it
     * would stay creatable by anyone crafting the POST (TWO-25279).
     */
    public function testNewlySubmittedNoneIsRejected(): void
    {
        $this->stubStoredConfig([
            'payment/two_payment/surcharge_tax_class' => '4',
        ]);
        $model = $this->buildModel([
            'value' => '0',
            'path' => 'payment/two_payment/surcharge_tax_class',
            'scope' => 'default',
            'fieldset_data' => ['surcharge_type' => 'percentage'],
        ]);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessageMatches('/no longer available/');
        $model->beforeSave();
    }

    public function testNewlySubmittedNoneIsRejectedWhenNothingIsStored(): void
    {
        $this->stubStoredConfig([]);
        $model = $this->buildModel([
            'value' => '0',
            'path' => 'payment/two_payment/surcharge_tax_class',
            'scope' => 'default',
            'fieldset_data' => ['surcharge_type' => 'percentage'],
        ]);

        $this->expectException(LocalizedException::class);
        $model->beforeSave();
    }

    /**
     * ...but a scope that ALREADY stores "None" must be able to resubmit it,
     * or the option the source model re-offers could never be saved and the
     * whole payment section would become unsavable.
     */
    public function testStoredNoneCanBeResubmitted(): void
    {
        $this->stubStoredConfig([
            'payment/two_payment/surcharge_tax_class' => '0',
        ]);
        $model = $this->buildModel([
            'value' => '0',
            'path' => 'payment/two_payment/surcharge_tax_class',
            'scope' => 'default',
            'fieldset_data' => ['surcharge_type' => 'percentage'],
        ]);

        $this->assertSame($model, $model->beforeSave());
    }

    /**
     * Migrated and pre-existing scopes reach the stored value by inheritance
     * too, so the check must read the sibling scope-anchored rather than
     * demanding an own row.
     */
    public function testStoredNoneCanBeResubmittedAtABrandScope(): void
    {
        $this->stubStoredConfig([
            'payment/overlay_payment/surcharge_tax_class' => '0',
        ]);
        $model = $this->buildModel([
            'value' => '0',
            'path' => 'payment/overlay_payment/surcharge_tax_class',
            'scope' => 'websites',
            'scope_id' => 2,
            'fieldset_data' => ['surcharge_type' => 'fixed'],
        ]);

        $this->assertSame($model, $model->beforeSave());
        // The scope actually reached ScopeConfigInterface — without this the
        // test would pass whether or not the read was scope-anchored, which
        // is precisely what it claims to prove.
        $this->assertContains(
            ['payment/overlay_payment/surcharge_tax_class', 'websites', 2],
            $this->configReads
        );
    }

    public function testStoredNoneInANumericVariantIsStillTreatedAsStored(): void
    {
        // The option source normalises numerically (Repository::
        // getSurchargeTaxClassIdAtScope int-casts), so a stored '0.0' offers
        // the option; this side must not then refuse it.
        $this->stubStoredConfig([
            'payment/two_payment/surcharge_tax_class' => '0.0',
        ]);
        $model = $this->buildModel([
            'value' => '0',
            'path' => 'payment/two_payment/surcharge_tax_class',
            'scope' => 'default',
            'fieldset_data' => ['surcharge_type' => 'percentage'],
        ]);

        $this->assertSame($model, $model->beforeSave());
    }

    public function testSiblingPathsAreDerivedBrandAware(): void
    {
        // Synthesized brand forms save under payment/<brand_code>/ — the
        // sibling lookup must follow the field's own path, not two_payment.
        $queried = [];
        $this->scopeConfig->method('getValue')->willReturnCallback(
            function ($path) use (&$queried) {
                $queried[] = $path;
                return $path === 'payment/overlay_payment/surcharge_type' ? 'fixed' : null;
            }
        );
        $model = $this->buildModel([
            'value' => '',
            'path' => 'payment/overlay_payment/surcharge_tax_class',
            'scope' => 'websites',
            'scope_id' => 2,
        ]);

        try {
            $model->beforeSave();
            $this->fail('Expected LocalizedException');
        } catch (LocalizedException $e) {
            $this->assertContains('payment/overlay_payment/surcharge_type', $queried);
        }
    }
}
