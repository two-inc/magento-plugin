<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Model\Config\Backend;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\Context;
use Magento\Framework\Registry;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Model\Config\Backend\PaymentTerms\OfferedTermsGuard;
use Two\Gateway\Model\Config\Backend\PaymentTermsCheckboxes;
use Two\Gateway\Service\Merchant\SettingsProvider;

/**
 * Tests PaymentTermsCheckboxes::beforeSave(): the offered-set guard, the mandatory-selection
 * guard that a legacy custom term satisfies on its own, and the fold-in that moves a deprecated
 * custom value onto the checkbox of the offered term it names, which the sibling clears in the
 * same save (TWO-25498, ABN-522).
 *
 * The fold-in must be reachable for a custom value matching an offered term that is NOT
 * currently ticked, since the available-terms set carries no tick state. An unresolvable
 * offered set must fold nothing in: it means "unknown", and moving a migration value on the
 * strength of an API outage would lose it.
 */
class PaymentTermsCheckboxesTest extends TestCase
{
    /** @var SettingsProvider|\PHPUnit\Framework\MockObject\MockObject */
    private $settingsProvider;

    protected function setUp(): void
    {
        $this->settingsProvider = $this->createMock(SettingsProvider::class);
    }

    private function buildModel(array $data): PaymentTermsCheckboxes
    {
        return new PaymentTermsCheckboxes(
            $this->getMockBuilder(Context::class)->disableOriginalConstructor()->getMock(),
            $this->getMockBuilder(Registry::class)->disableOriginalConstructor()->getMock(),
            $this->createMock(ScopeConfigInterface::class),
            $this->createMock(TypeListInterface::class),
            new OfferedTermsGuard($this->settingsProvider),
            null,
            null,
            $data
        );
    }

    public function testNoSelectionAndNoCustomTermIsRejected(): void
    {
        $this->settingsProvider->method('getAvailableTerms')->willReturn([14, 30]);
        $model = $this->buildModel([
            'value' => [],
            'scope' => 'default',
            'scope_id' => 0,
            'fieldset_data' => ['payment_terms_duration_days' => ''],
        ]);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Select at least one payment term or enter a custom term.');
        $model->beforeSave();
    }

    /**
     * @param string[] $ticked
     * @param int[] $offered
     * @dataProvider siblingCustomDaysProvider
     */
    public function testTheSiblingCustomDaysValueFoldsIntoTheTermItNames(
        array $ticked,
        string $custom,
        array $offered,
        string $expected,
        string $case
    ): void {
        $this->settingsProvider->method('getAvailableTerms')->willReturn($offered);
        $model = $this->buildModel([
            'value' => $ticked,
            'scope' => 'default',
            'scope_id' => 0,
            'fieldset_data' => ['payment_terms_duration_days' => $custom],
        ]);

        $model->beforeSave();

        $this->assertSame($expected, $model->getValue(), $case);
    }

    public static function siblingCustomDaysProvider(): array
    {
        return [
            [[], '30', [14, 30, 60], '30', 'an offered-but-unticked term is ticked by the fold-in'],
            [['14'], '30', [14, 30], '14,30', 'the fold-in joins the existing selection'],
            [['14'], '030', [14, 30], '14,30', 'a leading-zero value folds into the same term'],
            [['14'], '14', [14, 30], '14', 'a value duplicating a ticked term is not duplicated'],
            [['14'], '45', [14, 30], '14', 'a value outside the offered set has no term to fold into'],
            [['14'], '30', [], '14', 'an unresolvable offered set folds nothing in'],
            [['14'], 'abc', [14, 30], '14', 'an unusable value names no term'],
            [['14'], '0', [14, 30], '14', 'a zero names no term'],
            [['14'], '', [14, 30], '14', 'no custom term at all'],
        ];
    }

    /**
     * @param string[] $ticked
     * @param int[] $offered
     * @dataProvider unofferedSelectionProvider
     */
    public function testASelectionOutsideTheOfferedSetIsRefused(
        array $ticked,
        array $offered,
        string $message,
        string $case
    ): void {
        $this->settingsProvider->method('getAvailableTerms')->willReturn($offered);
        $model = $this->buildModel([
            'value' => $ticked,
            'scope' => 'default',
            'scope_id' => 0,
            'fieldset_data' => ['payment_terms_duration_days' => ''],
        ]);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage($message);
        $model->beforeSave();
        $this->fail($case);
    }

    public static function unofferedSelectionProvider(): array
    {
        return [
            [
                ['37'],
                [14, 30],
                'Payment terms you are not able to offer: 37 days. Choose from: 14, 30 days.',
                'a config:set CSV or tampered post naming an unoffered term is refused',
            ],
            [
                ['14', '37', '99'],
                [14, 30],
                'Payment terms you are not able to offer: 37, 99 days. Choose from: 14, 30 days.',
                'every unoffered term in the selection is named',
            ],
        ];
    }

    public function testAnUnresolvableOfferedSetCannotRefuseASelection(): void
    {
        // No offered terms at all means the merchant record did not resolve —
        // unknown, not "nothing offered", so the save must still go through.
        $this->settingsProvider->method('getAvailableTerms')->willReturn([]);
        $model = $this->buildModel([
            'value' => ['37'],
            'scope' => 'default',
            'scope_id' => 0,
            'fieldset_data' => ['payment_terms_duration_days' => ''],
        ]);

        $model->beforeSave();

        $this->assertSame('37', $model->getValue());
    }

    public function testResolvesTheOfferedSetAtTheStoreScopeBeingSaved(): void
    {
        $this->settingsProvider->method('getAvailableTerms')->willReturnCallback(
            function ($storeId) {
                return $storeId === 7 ? [30] : [14];
            }
        );
        $model = $this->buildModel([
            'value' => ['30'],
            'scope' => 'stores',
            'scope_id' => 7,
            'fieldset_data' => ['payment_terms_duration_days' => ''],
        ]);

        $model->beforeSave();

        $this->assertSame(
            '30',
            $model->getValue(),
            'a store-scope save must resolve available terms for that store, not the default scope'
        );
    }
}
