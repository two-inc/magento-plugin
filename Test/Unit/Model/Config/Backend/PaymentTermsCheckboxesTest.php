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
 * Tests PaymentTermsCheckboxes::beforeSave(): the pre-existing mandatory-
 * selection guard, and the TWO-25498 fold-in of a sibling custom-days value
 * that duplicates a merchant-offered term.
 *
 * The fold-in must be reachable for a custom value matching an offered term
 * that is NOT currently ticked — a prior implementation (on the matched
 * woocommerce-plugin change) only ever compared against the ticked subset,
 * which made that branch dead code. getFieldsetDataValue() reads the
 * sibling's POSTED value, which Magento populates for the whole group
 * before any field's beforeSave() runs, so the fold-in does not depend on
 * which field saves first.
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

    public function testFoldsInACustomValueThatMatchesAnUntickedOfferedTerm(): void
    {
        // Nothing is ticked, but the custom value (30) is one of the
        // merchant's offered terms — the fold-in must still tick it,
        // which is the exact case a ticked-only comparison would miss.
        $this->settingsProvider->method('getAvailableTerms')->willReturn([14, 30, 60]);
        $model = $this->buildModel([
            'value' => [],
            'scope' => 'default',
            'scope_id' => 0,
            'fieldset_data' => ['payment_terms_duration_days' => '30'],
        ]);

        $model->beforeSave();

        $this->assertSame('30', $model->getValue());
    }

    public function testFoldsInACustomValueThatDuplicatesAnAlreadyTickedTerm(): void
    {
        $this->settingsProvider->method('getAvailableTerms')->willReturn([14, 30]);
        $model = $this->buildModel([
            'value' => ['14'],
            'scope' => 'default',
            'scope_id' => 0,
            'fieldset_data' => ['payment_terms_duration_days' => '14'],
        ]);

        $model->beforeSave();

        $this->assertSame('14', $model->getValue(), 'the ticked term must not be duplicated');
    }

    public function testDoesNotFoldInAValueTheMerchantDoesNotOffer(): void
    {
        $this->settingsProvider->method('getAvailableTerms')->willReturn([14, 30]);
        $model = $this->buildModel([
            'value' => ['14'],
            'scope' => 'default',
            'scope_id' => 0,
            'fieldset_data' => ['payment_terms_duration_days' => '45'],
        ]);

        $model->beforeSave();

        $this->assertSame(
            '14',
            $model->getValue(),
            'a value outside the offered set has no term to fold into'
        );
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
            'value' => [],
            'scope' => 'stores',
            'scope_id' => 7,
            'fieldset_data' => ['payment_terms_duration_days' => '30'],
        ]);

        $model->beforeSave();

        $this->assertSame(
            '30',
            $model->getValue(),
            'a store-scope save must resolve available terms for that store, not the default scope'
        );
    }
}
