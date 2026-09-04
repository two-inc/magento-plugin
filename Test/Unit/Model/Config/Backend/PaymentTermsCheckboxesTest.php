<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Model\Config\Backend;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\Context;
use Magento\Framework\Registry;
use PHPUnit\Framework\TestCase;
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
            $this->settingsProvider,
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

    public function testDoesNotFoldInAGenuinelyCustomValue(): void
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
            'a genuinely custom value has no offered term to fold into'
        );
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
