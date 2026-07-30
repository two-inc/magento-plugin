<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Model\Config\Backend;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\BrandRegistryInterface;
use Two\Gateway\Api\Config\RepositoryInterface as ConfigRepository;
use Two\Gateway\Model\Config\Backend\SurchargeGrid;
use Two\Gateway\Service\Merchant\SettingsProvider;
use Two\Gateway\Service\Order\SurchargeCalculator;

/**
 * Tests the SurchargeGrid backend model's validation and write logic.
 *
 * Since Magento's Value base class has final/protected dependencies that
 * are hard to mock, we test the public contract via a thin subclass that
 * exposes afterSave() without requiring the full Magento model lifecycle.
 */
class SurchargeGridTest extends TestCase
{
    /** @var WriterInterface|\PHPUnit\Framework\MockObject\MockObject */
    private $configWriter;

    /** @var SurchargeGridTestable */
    private $model;

    protected function setUp(): void
    {
        $this->configWriter = $this->getMockBuilder(WriterInterface::class)
            ->getMock();
        $this->configWriter->method('save')->willReturn(null);
        $this->configWriter->method('delete')->willReturn(null);
        $this->model = new SurchargeGridTestable($this->configWriter);
    }

    public function testSavesValidValues(): void
    {
        $this->model->setTestValue([
            30 => ['fixed' => '10', 'percentage' => '25', 'limit' => '50'],
        ]);
        $this->model->setTestScope('default', 0);

        $saved = [];
        $this->configWriter->method('save')->willReturnCallback(
            function ($path, $value, $scope, $scopeId) use (&$saved) {
                $saved[] = [$path, $value];
            }
        );

        $this->model->callAfterSave();

        $this->assertCount(3, $saved);
        $this->assertContains(['payment/two_payment/surcharge_30_fixed', '10'], $saved);
        $this->assertContains(['payment/two_payment/surcharge_30_percentage', '25'], $saved);
        $this->assertContains(['payment/two_payment/surcharge_30_limit', '50'], $saved);
    }

    public function testEmptyValuesAreDeleted(): void
    {
        $this->model->setTestValue([
            30 => ['fixed' => '', 'percentage' => '25', 'limit' => ''],
        ]);
        $this->model->setTestScope('default', 0);

        $deleted = [];
        $this->configWriter->method('delete')->willReturnCallback(
            function ($path) use (&$deleted) {
                $deleted[] = $path;
            }
        );

        $saved = [];
        $this->configWriter->method('save')->willReturnCallback(
            function ($path, $value) use (&$saved) {
                $saved[] = $path;
            }
        );

        $this->model->callAfterSave();

        $this->assertCount(2, $deleted);
        $this->assertContains('payment/two_payment/surcharge_30_fixed', $deleted);
        $this->assertContains('payment/two_payment/surcharge_30_limit', $deleted);
        $this->assertEquals(['payment/two_payment/surcharge_30_percentage'], $saved);
    }

    public function testInheritFlagPurgesAllScopeOverrides(): void
    {
        // Grid-level inherit: the __inherit sentinel rides inside the value
        // array. Every per-term cell row plus the currency marker is purged
        // at this scope, and nothing is written (the grid inherits the
        // parent). This is the store-scope orphaned-override fix — no
        // orphaned override survives.
        $this->model->setTestValue([
            '__inherit' => '1',
            30 => ['fixed' => '10', 'percentage' => '25', 'limit' => '50'],
        ]);
        $this->model->setTestScope('websites', 1);

        $deleted = [];
        $this->configWriter->method('delete')->willReturnCallback(
            function ($path) use (&$deleted) {
                $deleted[] = $path;
            }
        );

        $saved = [];
        $this->configWriter->method('save')->willReturnCallback(
            function ($path) use (&$saved) {
                $saved[] = $path;
            }
        );

        $this->model->callAfterSave();

        $this->assertContains('payment/two_payment/surcharge_30_fixed', $deleted);
        $this->assertContains('payment/two_payment/surcharge_30_percentage', $deleted);
        $this->assertContains('payment/two_payment/surcharge_30_limit', $deleted);
        $this->assertContains('payment/two_payment/surcharge_fixed_currency', $deleted);
        $this->assertEmpty($saved, 'an inheriting grid writes nothing at the scope');
    }

    public function testRejectsNegativeValue(): void
    {
        // The limit cell is EMPTY, not '0': a zero limit is itself rejected
        // now (TWO-25289), and a fixture carrying one would let this test
        // pass for the wrong reason.
        $this->model->setTestValue([
            30 => ['fixed' => '-5', 'percentage' => '0', 'limit' => ''],
        ]);
        $this->model->setTestScope('default', 0);

        $this->expectException(LocalizedException::class);
        $this->model->callAfterSave();
    }

    public function testRejectsFixedAboveMax(): void
    {
        $this->model->setTestValue([
            30 => ['fixed' => '999', 'percentage' => '0', 'limit' => ''],
        ]);
        $this->model->setTestScope('default', 0);

        $this->expectException(LocalizedException::class);
        $this->model->callAfterSave();
    }

    public function testRejectsPercentageAboveMax(): void
    {
        $this->model->setTestValue([
            30 => ['fixed' => '0', 'percentage' => '150', 'limit' => ''],
        ]);
        $this->model->setTestScope('default', 0);

        $this->expectException(LocalizedException::class);
        $this->model->callAfterSave();
    }

    public function testIgnoresUnknownFieldTypes(): void
    {
        $this->model->setTestValue([
            30 => ['fixed' => '10', 'bogus' => '999'],
        ]);
        $this->model->setTestScope('default', 0);

        $saved = [];
        $this->configWriter->method('save')->willReturnCallback(
            function ($path) use (&$saved) {
                $saved[] = $path;
            }
        );

        $this->model->callAfterSave();

        $this->assertEquals(['payment/two_payment/surcharge_30_fixed'], $saved);
    }

    /**
     * Invoke the REAL backend model's private validator.
     *
     * The SurchargeGridTestable stub below reimplements afterSave()'s flow
     * (Value's lifecycle is awkward to construct), which makes it useless
     * for pinning a validation RULE: breaking the production rule cannot
     * turn a stub-only test red. So the rules below are asserted against
     * the production method itself. validateValue() reads no injected
     * dependency, so an instance built without the constructor is enough.
     */
    private function invokeValidateValue(
        string $type,
        string $rawValue,
        int $days = 30,
        bool $limitColumnVisible = true
    ): void {
        $model = (new \ReflectionClass(SurchargeGrid::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(SurchargeGrid::class, 'validateValue');
        $method->setAccessible(true);
        $method->invoke(
            $model,
            $type,
            $rawValue,
            $days,
            25,
            ConfigRepository::SURCHARGE_PERCENTAGE_MAX,
            $limitColumnVisible
        );
    }

    /**
     * Invoke the REAL type-gate that decides whether the Limit column is
     * visible.
     *
     * Only exercises the POSTED branch: the config fallback dereferences the
     * injected scope config, which an instance built without the constructor
     * does not have. That branch is covered separately by
     * testProductionTypeGateResolvesTheFallbackAtTheSavingScope, which injects
     * a stub via reflection.
     *
     * @param array<string, mixed> $groups
     */
    private function invokeHasPercentage(array $groups): bool
    {
        $model = (new \ReflectionClass(SurchargeGrid::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(SurchargeGrid::class, 'savedSurchargeTypeHasPercentage');
        $method->setAccessible(true);

        return (bool)$method->invoke($model, $groups, 'default', 0);
    }

    /**
     * The type gate reads the POSTED surcharge type, not the stored one: the
     * type and the grid are saved in the SAME request, so the stored value is
     * the previous one and would misjudge a merchant switching type — exactly
     * the case that decides whether a legacy zero limit blocks their save.
     */
    public function testProductionTypeGateReadsThePostedSurchargeType(): void
    {
        $post = static function (string $type): array {
            return ['payment_terms' => ['fields' => ['surcharge_type' => ['value' => $type]]]];
        };

        $this->assertTrue($this->invokeHasPercentage($post('percentage')));
        $this->assertTrue($this->invokeHasPercentage($post('fixed_and_percentage')));
        $this->assertFalse($this->invokeHasPercentage($post('fixed')));
        $this->assertFalse($this->invokeHasPercentage($post('none')));
    }

    public function testProductionValidatorRefusesAZeroLimit(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('a limit of 0 is not allowed');
        $this->invokeValidateValue('limit', '0');
    }

    public function testProductionValidatorAcceptsAPositiveLimit(): void
    {
        $this->expectNotToPerformAssertions();
        $this->invokeValidateValue('limit', '0.01');
    }

    /**
     * A sub-cent limit is refused for the same reason an exact 0 is: the
     * calculator rounds to 2dp before sending, so 0.001 arrives as a hard cap
     * of 0.00 and suppresses the whole fee. Refusing it is what makes the
     * "rounding direction cannot decide whether a configured cap survives"
     * claim in AGENTS.md true rather than aspirational.
     */
    public function testProductionValidatorRefusesASubCentLimit(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('a limit of 0 is not allowed');
        $this->invokeValidateValue('limit', '0.004');
    }

    /**
     * With the Limit column hidden, a legacy zero neither blocks the save nor
     * gets deleted — the whole row is stored as posted. Deleting it would
     * discard a VALID limit on a normal fixed-only save while the equally
     * inapplicable percentage cell survived, and at a non-default scope
     * deleting an override re-exposes the parent's value rather than retiring
     * anything.
     */
    public function testAHiddenLimitColumnNeitherBlocksTheSaveNorDeletesTheCell(): void
    {
        $this->model->setTestHasPercentage(false);
        $this->model->setTestValue([
            30 => ['fixed' => '10', 'percentage' => '', 'limit' => '0'],
            60 => ['fixed' => '10', 'percentage' => '', 'limit' => '50'],
        ]);
        $this->model->setTestScope('default', 0);

        $saved = [];
        $this->configWriter->method('save')->willReturnCallback(
            function ($path, $value) use (&$saved) {
                $saved[] = [$path, $value];
            }
        );

        $this->model->callAfterSave();

        $this->assertContains(['payment/two_payment/surcharge_30_limit', '0'], $saved);
        $this->assertContains(['payment/two_payment/surcharge_60_limit', '50'], $saved);
    }

    /**
     * A non-numeric cell gets its own error. Cast to float it would be 0.0 and
     * a limit would be reported as "a limit of 0 is not allowed", which is
     * both wrong and unactionable. Nothing checked numeric input server-side
     * before — the JS does, but the direct-POST paths this backend exists to
     * cover skip it.
     */
    public function testProductionValidatorRejectsNonNumericOnItsOwnTerms(): void
    {
        // Cast to float 'abc' is 0.0, so without the raw-string check a
        // non-numeric limit was reported as "a limit of 0 is not allowed" —
        // both wrong and unactionable.
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('value must be a number');
        $this->invokeValidateValue('limit', 'abc');
    }

    /**
     * Zero on the fixed and percentage cells is the sanctioned way to say
     * "charge nothing on this term" — it is precisely what the zero-limit
     * error message tells the admin to do, so it must stay accepted.
     */
    public function testProductionValidatorAcceptsZeroFixedAndZeroPercentage(): void
    {
        $this->expectNotToPerformAssertions();
        $this->invokeValidateValue('fixed', '0');
        $this->invokeValidateValue('percentage', '0');
    }

    /**
     * The config fallback is the NORMAL path at a non-default scope: leaving
     * the surcharge-type field on "Use Default Value" renders its select
     * disabled, and browsers do not submit disabled inputs, so nothing is
     * posted for it. An UNSCOPED read resolves the default scope's value,
     * which is the wrong answer for exactly the store that inherits a
     * different one — either deleting a store's real limits or blocking its
     * whole section save.
     */
    public function testProductionTypeGateResolvesTheFallbackAtTheSavingScope(): void
    {
        $reads = [];
        $config = $this->getMockBuilder(ScopeConfigInterface::class)->getMock();
        $config->method('getValue')->willReturnCallback(
            function ($path, $scope = 'default', $scopeId = null) use (&$reads) {
                $reads[] = [$path, $scope, $scopeId];

                // Default scope is fixed-only; the website overrides to
                // percentage. An unscoped read would answer "fixed".
                return $scope === 'websites' ? 'percentage' : 'fixed';
            }
        );

        $model = (new \ReflectionClass(SurchargeGrid::class))->newInstanceWithoutConstructor();
        $configProperty = new \ReflectionProperty(\Magento\Framework\App\Config\Value::class, '_config');
        $configProperty->setAccessible(true);
        $configProperty->setValue($model, $config);
        $brand = $this->getMockBuilder(BrandRegistryInterface::class)->getMock();
        $brand->method('getCode')->willReturn('two_payment');
        $brandProperty = new \ReflectionProperty(SurchargeGrid::class, 'brandRegistry');
        $brandProperty->setAccessible(true);
        $brandProperty->setValue($model, $brand);

        $method = new \ReflectionMethod(SurchargeGrid::class, 'savedSurchargeTypeHasPercentage');
        $method->setAccessible(true);

        $this->assertTrue(
            (bool)$method->invoke($model, [], 'websites', 1),
            'the website scope overrides to percentage, so the Limit column IS visible there'
        );
        $this->assertSame(
            ['payment/two_payment/surcharge_type', 'websites', 1],
            $reads[0],
            'the fallback must read at the saving scope, not unscoped'
        );
    }

    /**
     * `is_numeric('1e400')` is true and the cast is INF. Limit is the one
     * column with no upper bound, so INF would be stored and then fail the
     * pricing request at serialisation time, a long way from the cause.
     */
    public function testProductionValidatorRefusesANonFiniteLimit(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('value must be a number');
        $this->invokeValidateValue('limit', '1e400');
    }

    /**
     * While the Limit column is hidden the zero rule is SKIPPED, so a legacy
     * zero cannot block a save over a cell the admin can neither see nor
     * clear. The value is still stored — deleting it would discard a valid
     * limit on a normal fixed-only save, and at a non-default scope deleting
     * an override re-exposes the parent's value rather than retiring it.
     */
    public function testProductionValidatorSkipsTheZeroRuleWhileTheColumnIsHidden(): void
    {
        $this->expectNotToPerformAssertions();
        $this->invokeValidateValue('limit', '0', 30, false);
        $this->invokeValidateValue('limit', '0.001', 30, false);
    }

    /**
     * Build the REAL backend model and run its REAL afterSave().
     *
     * Everything else in this file either drives the SurchargeGridTestable
     * reimplementation or reaches into a single private method, so neither can
     * see how afterSave() wires the two together. `validateValue()` defaults
     * $limitColumnVisible to true, so dropping the argument at the call site —
     * or dropping the `&& $limitColumnVisible` term from the rule — compiles
     * and leaves every other test in this file green while reintroducing the
     * failed-section-save regression. This helper exists to make that red.
     *
     * The model is built without its constructor and has only the
     * dependencies this path touches injected: at the default scope with no
     * merchant surcharge limit, the store manager and the FX rates provider
     * are never reached, and the resource connection is only used by the
     * inherit purge.
     *
     * @param array<int, array<string, string>> $grid
     * @return list<array{0: string, 1: string}> the (path, value) pairs saved
     */
    private function runProductionAfterSave(string $postedType, array $grid): array
    {
        $config = $this->getMockBuilder(ScopeConfigInterface::class)->getMock();
        $config->method('getValue')->willReturnCallback(
            static function ($path) {
                return $path === 'currency/options/base' ? 'EUR' : null;
            }
        );

        $brand = $this->getMockBuilder(BrandRegistryInterface::class)->getMock();
        $brand->method('getCode')->willReturn('two_payment');

        // No merchant-side surcharge cap, so the fixed upper-bound check is
        // skipped and the FX rates provider is never consulted.
        $settings = $this->getMockBuilder(SettingsProvider::class)
            ->disableOriginalConstructor()
            ->getMock();
        $settings->method('getSurchargeLimit')->willReturn(null);

        $saved = [];
        $writer = $this->getMockBuilder(WriterInterface::class)->getMock();
        $writer->method('save')->willReturnCallback(
            function ($path, $value) use (&$saved) {
                $saved[] = [$path, $value];
            }
        );
        $writer->method('delete')->willReturn(null);

        $model = (new \ReflectionClass(SurchargeGrid::class))->newInstanceWithoutConstructor();
        $inject = static function (string $class, string $property, $value) use ($model): void {
            $reflected = new \ReflectionProperty($class, $property);
            $reflected->setAccessible(true);
            $reflected->setValue($model, $value);
        };
        $inject(\Magento\Framework\App\Config\Value::class, '_config', $config);
        $inject(SurchargeGrid::class, 'brandRegistry', $brand);
        $inject(SurchargeGrid::class, 'settingsProvider', $settings);
        $inject(SurchargeGrid::class, 'configWriter', $writer);

        $model->setData('scope', 'default');
        $model->setData('scope_id', 0);
        $model->setData('groups', [
            'payment_terms' => [
                'fields' => [
                    'surcharge_type' => ['value' => $postedType],
                    'surcharge_grid' => ['value' => $grid],
                ],
            ],
        ]);

        $model->afterSave();

        return $saved;
    }

    /**
     * afterSave() must thread the Limit column's REAL visibility into the zero
     * rule. With the surcharge type posted as fixed-only the column is hidden,
     * so a legacy zero must sail through the whole save — not throw, and not
     * be deleted.
     *
     * Deleting the sixth argument at the call site, or the `&&
     * $limitColumnVisible` term from the rule itself, turns this red: the
     * parameter's `true` default means the zero rule fires on a cell the admin
     * can neither see nor clear, and the merchant's entire payment section
     * fails to save.
     */
    public function testProductionAfterSaveWiresTheLimitColumnVisibilityIntoTheZeroRule(): void
    {
        $saved = $this->runProductionAfterSave('fixed', [
            30 => ['fixed' => '10', 'percentage' => '0', 'limit' => '0'],
        ]);

        $this->assertContains(
            ['payment/two_payment/surcharge_30_limit', '0'],
            $saved,
            'a legacy zero limit must be stored as posted while the column is hidden'
        );
        $this->assertContains(['payment/two_payment/surcharge_30_fixed', '10'], $saved);
    }

    /**
     * The mirror case, so the wiring cannot be satisfied by hardcoding the
     * flag to false (which would silently drop the zero rule for everyone).
     * With a percentage posted the column IS visible and a zero must be
     * refused by the real afterSave() path.
     */
    public function testProductionAfterSaveStillRefusesAZeroLimitWhileTheColumnIsVisible(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('a limit of 0 is not allowed');
        $this->runProductionAfterSave('fixed_and_percentage', [
            30 => ['fixed' => '10', 'percentage' => '25', 'limit' => '0'],
        ]);
    }

    /**
     * The two MONEY_DECIMALS constants must agree: the whole correctness
     * argument is that the grid refuses any limit that rounds away at the
     * precision the request is rounded to. If they drift, a limit passes the
     * grid and is then rounded into a fee-suppressing zero.
     */
    public function testTheGridAndTheCalculatorAgreeOnMoneyPrecision(): void
    {
        $grid = new \ReflectionClass(SurchargeGrid::class);
        $calculator = new \ReflectionClass(SurchargeCalculator::class);

        $this->assertSame(
            $calculator->getConstant('MONEY_DECIMALS'),
            $grid->getConstant('MONEY_DECIMALS'),
            'the grid must refuse limits at the same precision the request is rounded to'
        );
    }

    public function testNonArrayValueIsNoOp(): void
    {
        $writer = $this->createMock(WriterInterface::class);
        $writer->expects($this->never())->method('save');
        $writer->expects($this->never())->method('delete');

        $model = new SurchargeGridTestable($writer);
        $model->setTestValue('');
        $model->setTestScope('default', 0);
        $model->callAfterSave();
    }
}

/**
 * Testable subclass that avoids Magento's model lifecycle dependencies.
 * Exposes the afterSave logic via callAfterSave().
 */
class SurchargeGridTestable
{
    private const FIELDS = ['fixed', 'percentage', 'limit'];

    private $configWriter;
    private $value;
    private $scope = 'default';
    private $scopeId = 0;
    private $hasPercentage = true;

    public function __construct(WriterInterface $configWriter)
    {
        $this->configWriter = $configWriter;
    }

    public function setTestValue($value): void
    {
        $this->value = $value;
    }

    public function setTestScope(string $scope, int $scopeId): void
    {
        $this->scope = $scope;
        $this->scopeId = $scopeId;
    }

    public function setTestHasPercentage(bool $hasPercentage): void
    {
        $this->hasPercentage = $hasPercentage;
    }

    public function callAfterSave(): void
    {
        if (!is_array($this->value)) {
            return;
        }

        $value = $this->value;

        // Grid-level inherit: purge every per-term cell + currency marker
        // at this scope, write nothing. Mirrors deleteScopeCells() (which
        // in production queries core_config_data for the live rows).
        if (!empty($value['__inherit'])) {
            foreach ($value as $days => $fields) {
                if ($days === '__inherit' || !is_array($fields)) {
                    continue;
                }
                foreach (self::FIELDS as $type) {
                    $this->configWriter->delete(
                        sprintf('payment/two_payment/surcharge_%d_%s', (int)$days, $type),
                        $this->scope,
                        $this->scopeId
                    );
                }
            }
            $this->configWriter->delete(
                'payment/two_payment/surcharge_fixed_currency',
                $this->scope,
                $this->scopeId
            );
            return;
        }
        unset($value['__inherit']);

        // Hard-coded to the merchant's surcharge cap for this test — in
        // production it comes from SettingsProvider::getSurchargeLimit()
        // (the GET /v1/merchant surcharge_limit).
        $maxFixed = 25;
        $maxPercentage = ConfigRepository::SURCHARGE_PERCENTAGE_MAX;
        // Mirrors savedSurchargeTypeHasPercentage(); injectable so a test can
        // exercise the fixed-only path where the Limit column is not live.
        $limitColumnVisible = $this->hasPercentage;

        foreach ($value as $days => $fields) {
            if (!is_array($fields)) {
                continue;
            }
            $days = (int)$days;

            foreach ($fields as $type => $cellValue) {
                if (!in_array($type, self::FIELDS, true)) {
                    continue;
                }

                $path = sprintf('payment/two_payment/surcharge_%d_%s', $days, $type);

                $cellValue = (string)$cellValue;
                if ($cellValue === '') {
                    $this->configWriter->delete($path, $this->scope, $this->scopeId);
                    continue;
                }

                // Mirrors validateValue()'s raw-string checks; pinned for
                // real against the production method by the
                // testProductionValidator* cases above.
                if (!is_numeric($cellValue)) {
                    throw new LocalizedException(
                        __('%1 days - %2: value must be a number.', $days, $type)
                    );
                }

                $numericValue = (float)$cellValue;
                if ($numericValue < 0) {
                    throw new LocalizedException(
                        __('%1 days - %2: value cannot be negative.', $days, $type)
                    );
                }
                // Mirrors SurchargeGrid::validateValue (TWO-25289). Pinned
                // for real against the production method by
                // testProductionValidatorRefusesAZeroLimit — this copy only
                // keeps the flow tests above faithful to the real save.
                if ($type === 'limit' && $limitColumnVisible && round($numericValue, 2) === 0.0) {
                    throw new LocalizedException(
                        __(
                            '%1 days - limit: a limit of 0 is not allowed. To charge nothing on this term,'
                            . ' set the fixed amount and percentage to 0 instead, and leave the limit empty.',
                            $days
                        )
                    );
                }
                if ($type === 'fixed' && $numericValue > $maxFixed) {
                    throw new LocalizedException(
                        __('%1 days - fixed amount: maximum is %2.', $days, $maxFixed)
                    );
                }
                if ($type === 'percentage' && $numericValue > $maxPercentage) {
                    throw new LocalizedException(
                        __('%1 days - percentage: maximum is %2.', $days, $maxPercentage)
                    );
                }

                $this->configWriter->save($path, $cellValue, $this->scope, $this->scopeId);
            }
        }
    }
}
