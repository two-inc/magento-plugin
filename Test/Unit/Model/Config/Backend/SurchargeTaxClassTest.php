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

    private function stubStoredConfig(array $map): void
    {
        $this->scopeConfig->method('getValue')->willReturnCallback(
            function ($path) use ($map) {
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
        $this->expectExceptionMessage('select a surcharge tax treatment');
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

    public function testSiblingPathsAreDerivedBrandAware(): void
    {
        // Synthesized brand forms save under payment/<brand_code>/ — the
        // sibling lookup must follow the field's own path, not two_payment.
        $queried = [];
        $this->scopeConfig->method('getValue')->willReturnCallback(
            function ($path) use (&$queried) {
                $queried[] = $path;
                return $path === 'payment/abn_payment/surcharge_type' ? 'fixed' : null;
            }
        );
        $model = $this->buildModel([
            'value' => '',
            'path' => 'payment/abn_payment/surcharge_tax_class',
            'scope' => 'websites',
            'scope_id' => 2,
        ]);

        try {
            $model->beforeSave();
            $this->fail('Expected LocalizedException');
        } catch (LocalizedException $e) {
            $this->assertContains('payment/abn_payment/surcharge_type', $queried);
        }
    }
}
