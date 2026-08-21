<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Model\Config\Backend;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Model\Context;
use Magento\Framework\Registry;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Model\Config\Backend\PaymentTermsCustomDays;
use Two\Gateway\Service\Merchant\SettingsProvider;

/**
 * Tests the save-time half of the "Custom Payment Terms (days)"
 * genuineness rule (TWO-25498): a custom value that duplicates a
 * merchant-offered term — ticked or not, since SettingsProvider's
 * available-terms set does not carry tick state — is cleared here. The
 * matching fold-in (ticking that term's checkbox) is on the sibling
 * PaymentTermsCheckboxes backend model, tested separately.
 */
class PaymentTermsCustomDaysTest extends TestCase
{
    /** @var SettingsProvider|\PHPUnit\Framework\MockObject\MockObject */
    private $settingsProvider;

    protected function setUp(): void
    {
        $this->settingsProvider = $this->createMock(SettingsProvider::class);
    }

    private function buildModel(array $data): PaymentTermsCustomDays
    {
        return new PaymentTermsCustomDays(
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

    public function testEmptyValueIsLeftEmpty(): void
    {
        $this->settingsProvider->method('getAvailableTerms')->willReturn([14, 30]);
        $model = $this->buildModel(['value' => '', 'scope' => 'default', 'scope_id' => 0]);

        $model->beforeSave();

        $this->assertSame('', $model->getValue());
    }

    public function testValueMatchingAnOfferedTermIsCleared(): void
    {
        // SettingsProvider's set carries no tick state, so this covers both
        // the ticked and the offered-but-unticked case identically — the
        // distinction only exists on the sibling checkbox field's saved
        // selection, not here.
        $this->settingsProvider->method('getAvailableTerms')->willReturn([14, 30, 60]);
        $model = $this->buildModel(['value' => '30', 'scope' => 'default', 'scope_id' => 0]);

        $model->beforeSave();

        $this->assertSame('', $model->getValue());
    }

    public function testGenuinelyCustomValueIsPreserved(): void
    {
        $this->settingsProvider->method('getAvailableTerms')->willReturn([14, 30]);
        $model = $this->buildModel(['value' => '45', 'scope' => 'default', 'scope_id' => 0]);

        $model->beforeSave();

        $this->assertSame('45', $model->getValue());
    }

    public function testResolvesTheOfferedSetAtTheStoreScopeBeingSaved(): void
    {
        $this->settingsProvider->method('getAvailableTerms')->willReturnCallback(
            function ($storeId) {
                return $storeId === 5 ? [45] : [14, 30];
            }
        );
        $model = $this->buildModel(['value' => '45', 'scope' => 'stores', 'scope_id' => 5]);

        $model->beforeSave();

        $this->assertSame(
            '',
            $model->getValue(),
            'a store-scope save must resolve available terms for that store, not the default scope'
        );
    }
}
