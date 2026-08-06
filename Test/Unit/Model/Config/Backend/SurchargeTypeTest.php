<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Model\Config\Backend;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\Context;
use Magento\Framework\Registry;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Model\Config\Backend\SurchargeType;

/**
 * Tests the section-save half of the surcharge tax treatment invariant.
 *
 * The Surcharge method field is posted on every admin save of the payment
 * section, so this guard is what catches a save triggered by any other
 * field — including a shop already stored in the enabled-with-blank-
 * treatment state. A pre-existing legacy flat rate counts as an explicit
 * choice (including a rate of 0).
 */
class SurchargeTypeTest extends TestCase
{
    /** @var ScopeConfigInterface|\PHPUnit\Framework\MockObject\MockObject */
    private $scopeConfig;

    protected function setUp(): void
    {
        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);
    }

    private function buildModel(array $data): SurchargeType
    {
        return new SurchargeType(
            $this->getMockBuilder(Context::class)->disableOriginalConstructor()->getMock(),
            $this->getMockBuilder(Registry::class)->disableOriginalConstructor()->getMock(),
            $this->scopeConfig,
            $this->createMock(TypeListInterface::class),
            null,
            null,
            $data
        );
    }

    private function stubStoredConfig(array $map): void
    {
        $this->scopeConfig->method('getValue')->willReturnCallback(
            function ($path) use ($map) {
                return $map[$path] ?? null;
            }
        );
    }

    public function testEnablingSurchargeWithNoTreatmentAnywhereIsRejected(): void
    {
        $this->stubStoredConfig([]);
        $model = $this->buildModel([
            'value' => 'percentage',
            'path' => 'payment/two_payment/surcharge_type',
            'scope' => 'default',
            'fieldset_data' => [
                'surcharge_type' => 'percentage',
                'surcharge_tax_class' => '',
            ],
        ]);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Please select a surcharge tax treatment');
        $model->beforeSave();
    }

    public function testAlreadyBrokenShopSavingAnUnrelatedFieldIsRejected(): void
    {
        // Stored state: surcharge enabled, treatment blank. The merchant edits
        // some other field in the section; surcharge_type is posted unchanged
        // and the treatment field is not part of this save at all.
        $this->stubStoredConfig([
            'payment/two_payment/surcharge_type' => 'fixed',
            'payment/two_payment/surcharge_tax_class' => null,
            'payment/two_payment/surcharge_tax_rate' => null,
        ]);
        $model = $this->buildModel([
            'value' => 'fixed',
            'path' => 'payment/two_payment/surcharge_type',
            'scope' => 'default',
            'fieldset_data' => ['surcharge_type' => 'fixed'],
        ]);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Surcharge tax treatment field');
        $model->beforeSave();
    }

    public function testLegacyFlatRateCountsAsAnExplicitTreatment(): void
    {
        $this->stubStoredConfig(['payment/two_payment/surcharge_tax_rate' => '21']);
        $model = $this->buildModel([
            'value' => 'percentage',
            'path' => 'payment/two_payment/surcharge_type',
            'scope' => 'default',
            'fieldset_data' => [
                'surcharge_type' => 'percentage',
                'surcharge_tax_class' => '',
            ],
        ]);

        $this->assertSame($model, $model->beforeSave());
    }

    public function testLegacyFlatRateOfZeroCountsAsAnExplicitTreatment(): void
    {
        // Falsy-zero guard: a configured rate of "0" is a real value.
        $this->stubStoredConfig(['payment/two_payment/surcharge_tax_rate' => '0']);
        $model = $this->buildModel([
            'value' => 'percentage',
            'path' => 'payment/two_payment/surcharge_type',
            'scope' => 'default',
            'fieldset_data' => [
                'surcharge_type' => 'percentage',
                'surcharge_tax_class' => '',
            ],
        ]);

        $this->assertSame($model, $model->beforeSave());
    }

    public function testDisabledSurchargeWithBlankTreatmentIsAccepted(): void
    {
        $this->stubStoredConfig([]);
        $model = $this->buildModel([
            'value' => 'none',
            'path' => 'payment/two_payment/surcharge_type',
            'scope' => 'default',
            'fieldset_data' => [
                'surcharge_type' => 'none',
                'surcharge_tax_class' => '',
            ],
        ]);

        $this->assertSame($model, $model->beforeSave());
    }

    public function testEnablingAndPickingATreatmentInTheSameSaveIsAccepted(): void
    {
        // Nothing stored yet — the treatment only exists in the posted data.
        $this->stubStoredConfig([]);
        $model = $this->buildModel([
            'value' => 'percentage',
            'path' => 'payment/two_payment/surcharge_type',
            'scope' => 'default',
            'fieldset_data' => [
                'surcharge_type' => 'percentage',
                'surcharge_tax_class' => '4',
            ],
        ]);

        $this->assertSame($model, $model->beforeSave());
    }

    public function testStoredTreatmentSatisfiesTheGuardWhenNotPosted(): void
    {
        $this->stubStoredConfig(['payment/two_payment/surcharge_tax_class' => '4']);
        $model = $this->buildModel([
            'value' => 'percentage',
            'path' => 'payment/two_payment/surcharge_type',
            'scope' => 'default',
            'fieldset_data' => ['surcharge_type' => 'percentage'],
        ]);

        $this->assertSame($model, $model->beforeSave());
    }

    public function testOwnValueWinsOverStoredSurchargeType(): void
    {
        // Stored config says enabled; this save switches it off, so the blank
        // treatment must be accepted.
        $this->stubStoredConfig(['payment/two_payment/surcharge_type' => 'percentage']);
        $model = $this->buildModel([
            'value' => 'none',
            'path' => 'payment/two_payment/surcharge_type',
            'scope' => 'default',
            'fieldset_data' => [
                'surcharge_type' => 'none',
                'surcharge_tax_class' => '',
            ],
        ]);

        $this->assertSame($model, $model->beforeSave());
    }

    public function testSystemXmlWiresTheGuardOntoBothFields(): void
    {
        // The wiring IS the fix: without the backend_model on surcharge_type
        // the invariant is only enforced when the treatment field itself is
        // part of the save.
        $systemXml = dirname(__DIR__, 5) . '/etc/adminhtml/system.xml';
        $xml = new \SimpleXMLElement((string)file_get_contents($systemXml));

        $backendModels = [];
        foreach (['surcharge_type', 'surcharge_tax_class'] as $fieldId) {
            $nodes = $xml->xpath(sprintf('//field[@id="%s"]/backend_model', $fieldId));
            $backendModels[$fieldId] = $nodes ? (string)$nodes[0] : null;
        }

        $this->assertSame(
            \Two\Gateway\Model\Config\Backend\SurchargeType::class,
            $backendModels['surcharge_type']
        );
        $this->assertSame(
            \Two\Gateway\Model\Config\Backend\SurchargeTaxClass::class,
            $backendModels['surcharge_tax_class']
        );
    }

    public function testOwnValueEnablesTheGuardWhenNoFieldsetDataIsPresent(): void
    {
        // PreparedValueFactory-style saves (app:config:import and friends) set
        // path/value/scope with no fieldset_data at all. The field's own value
        // must still drive the check — stored config still says "none".
        $this->stubStoredConfig([
            'payment/two_payment/surcharge_type' => 'none',
            'payment/two_payment/surcharge_tax_class' => '',
            'payment/two_payment/surcharge_tax_rate' => '',
        ]);
        $model = $this->buildModel([
            'value' => 'percentage',
            'path' => 'payment/two_payment/surcharge_type',
            'scope' => 'default',
        ]);

        $this->expectException(LocalizedException::class);
        $model->beforeSave();
    }

    public function testSiblingPathsAreDerivedBrandAware(): void
    {
        // Synthesized brand forms save under payment/<brand_code>/ — sibling
        // lookups must follow the field's own path, not two_payment.
        $queried = [];
        $this->scopeConfig->method('getValue')->willReturnCallback(
            function ($path) use (&$queried) {
                $queried[] = $path;
                return null;
            }
        );
        $model = $this->buildModel([
            'value' => 'percentage',
            'path' => 'payment/overlay_payment/surcharge_type',
            'scope' => 'websites',
            'scope_id' => 2,
            'fieldset_data' => ['surcharge_type' => 'percentage'],
        ]);

        try {
            $model->beforeSave();
            $this->fail('Expected LocalizedException');
        } catch (LocalizedException $e) {
            $this->assertContains('payment/overlay_payment/surcharge_tax_class', $queried);
            $this->assertContains('payment/overlay_payment/surcharge_tax_rate', $queried);
        }
    }
}
