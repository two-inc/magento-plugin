<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Model\Config\Source;

use Magento\Framework\App\RequestInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Api\Data\WebsiteInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Tax\Model\TaxClass\Source\Product as ProductTaxClassSource;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\Config\RepositoryInterface as ConfigRepository;
use Two\Gateway\Model\Config\Source\SurchargeTaxClass;

/**
 * The surcharge tax treatment selector never auto-defaults: first
 * option is always the unselected placeholder, and the deprecated
 * "Custom" flat-rate treatment only appears for merchants with a
 * genuinely pre-existing legacy rate value.
 *
 * Core's never-taxed "None" (class id 0) is suppressed for new
 * selections and re-injected only for a scope that already stores it,
 * so a merchant sitting on that stored value can still save.
 */
class SurchargeTaxClassTest extends TestCase
{
    /** @var ProductTaxClassSource|\PHPUnit\Framework\MockObject\MockObject */
    private $productTaxClassSource;

    /** @var ConfigRepository|\PHPUnit\Framework\MockObject\MockObject */
    private $configRepository;

    /** @var RequestInterface|\PHPUnit\Framework\MockObject\MockObject */
    private $request;

    /** @var StoreManagerInterface|\PHPUnit\Framework\MockObject\MockObject */
    private $storeManager;

    /** @var SurchargeTaxClass */
    private $source;

    protected function setUp(): void
    {
        $this->productTaxClassSource = $this->getMockBuilder(ProductTaxClassSource::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getAllOptions'])
            ->getMock();
        $this->productTaxClassSource->method('getAllOptions')->with(true)->willReturn([
            ['value' => '0', 'label' => 'None'],
            ['value' => '2', 'label' => 'Taxable Goods'],
        ]);
        $this->configRepository = $this->createMock(ConfigRepository::class);
        $this->request = $this->createMock(RequestInterface::class);
        $this->storeManager = $this->createMock(StoreManagerInterface::class);

        $this->source = new SurchargeTaxClass(
            $this->productTaxClassSource,
            $this->configRepository,
            $this->request,
            $this->storeManager
        );
    }

    public function testFirstOptionIsAlwaysUnselectedPlaceholder(): void
    {
        $this->configRepository->method('hasCustomSurchargeTaxRateAtScope')->willReturn(false);
        $options = $this->source->toOptionArray();

        $this->assertSame('', $options[0]['value']);
        $this->assertSame('-- Select surcharge tax treatment --', (string)$options[0]['label']);
    }

    public function testCustomOptionHiddenWhenNoLegacyRateExists(): void
    {
        $this->configRepository->method('hasCustomSurchargeTaxRateAtScope')->willReturn(false);
        $values = array_column($this->source->toOptionArray(), 'value');

        $this->assertNotContains(SurchargeTaxClass::CUSTOM, $values);
        $this->assertSame(['', '2'], $values);
    }

    public function testCustomOptionShownWhenLegacyRateExists(): void
    {
        $this->configRepository->method('hasCustomSurchargeTaxRateAtScope')->willReturn(true);
        $values = array_column($this->source->toOptionArray(), 'value');

        $this->assertSame(['', SurchargeTaxClass::CUSTOM, '2'], $values);
    }

    public function testCoreNoneIsSuppressedForNewSelections(): void
    {
        // No stored value at this scope: "None" is a platform default,
        // not a merchant-configured tax rule, so it must not be offered.
        $this->configRepository->method('getSurchargeTaxClassIdAtScope')->willReturn(null);
        $values = array_column($this->source->toOptionArray(), 'value');

        $this->assertNotContains('0', $values);
    }

    public function testCoreNoneStaysSuppressedWhenAnotherClassIsStored(): void
    {
        $this->configRepository->method('getSurchargeTaxClassIdAtScope')->willReturn(2);
        $values = array_column($this->source->toOptionArray(), 'value');

        $this->assertNotContains('0', $values);
        $this->assertSame(['', '2'], $values);
    }

    public function testCoreNoneIsOfferedWhenItIsAlreadyTheStoredValue(): void
    {
        // The lockout case: a select cannot render a value absent from
        // its options, so it would fall back to the placeholder and the
        // next save would post '' — which the treatment guard rejects,
        // rolling back the whole payment-section save.
        $this->configRepository->method('getSurchargeTaxClassIdAtScope')->willReturn(0);
        $values = array_column($this->source->toOptionArray(), 'value');

        $this->assertContains('0', $values);
        $this->assertSame(['', '0', '2'], $values);
    }

    public function testReinjectedNoneKeepsCoreOwnLabel(): void
    {
        $this->configRepository->method('getSurchargeTaxClassIdAtScope')->willReturn(0);
        $labels = [];
        foreach ($this->source->toOptionArray() as $option) {
            $labels[(string)$option['value']] = (string)$option['label'];
        }

        $this->assertSame('None', $labels['0']);
    }

    public function testStoredValueIsReadAtTheRequestedStoreScope(): void
    {
        // A store view that inherits "None" from the default scope must
        // still render it; reading the stored value at the wrong scope
        // is what would re-create the lockout for that store view.
        $this->request->method('getParam')->willReturnCallback(
            fn ($key) => $key === 'store' ? 'store_two' : null
        );
        $store = $this->createMock(StoreInterface::class);
        $store->method('getId')->willReturn(7);
        $this->storeManager->method('getStore')->with('store_two')->willReturn($store);

        $this->configRepository->expects($this->once())
            ->method('getSurchargeTaxClassIdAtScope')
            ->with('store', 7)
            ->willReturn(0);

        $values = array_column($this->source->toOptionArray(), 'value');
        $this->assertContains('0', $values);
    }

    public function testExistenceCheckUsesRequestedStoreScope(): void
    {
        $this->request->method('getParam')->willReturnCallback(
            fn ($key) => $key === 'store' ? 'store_two' : null
        );
        $store = $this->createMock(StoreInterface::class);
        $store->method('getId')->willReturn(7);
        $this->storeManager->method('getStore')->with('store_two')->willReturn($store);

        $this->configRepository->expects($this->once())
            ->method('hasCustomSurchargeTaxRateAtScope')
            ->with('store', 7)
            ->willReturn(true);

        $values = array_column($this->source->toOptionArray(), 'value');
        $this->assertContains(SurchargeTaxClass::CUSTOM, $values);
    }

    /**
     * Website scope reads the WEBSITE row, not the website's default store
     * view. Resolving through a store view reads a deeper override, so a
     * website storing "None" under a store view storing a real class would
     * render the placeholder over its own stored value.
     */
    public function testExistenceCheckReadsTheWebsiteScopeItself(): void
    {
        $this->request->method('getParam')->willReturnCallback(
            fn ($key) => $key === 'website' ? 'base' : null
        );
        $website = $this->createMock(WebsiteInterface::class);
        $website->method('getId')->willReturn(4);
        $this->storeManager->method('getWebsite')->with('base')->willReturn($website);
        $this->storeManager->expects($this->never())->method('getGroup');

        $this->configRepository->expects($this->once())
            ->method('hasCustomSurchargeTaxRateAtScope')
            ->with('website', 4)
            ->willReturn(true);

        $values = array_column($this->source->toOptionArray(), 'value');
        $this->assertContains(SurchargeTaxClass::CUSTOM, $values);
    }

    /**
     * No scope params means the DEFAULT scope, named explicitly. A null store
     * id would resolve the current store instead, so a default-scope form on
     * a store that overrides the value would read the override.
     */
    public function testNoScopeParamsReadTheDefaultScopeExplicitly(): void
    {
        $this->configRepository->expects($this->once())
            ->method('getSurchargeTaxClassIdAtScope')
            ->with('default', null)
            ->willReturn(0);

        $values = array_column($this->source->toOptionArray(), 'value');
        $this->assertContains('0', $values);
    }

    public function testNeverTaxedCarveOutIsReadAtTheWebsiteScope(): void
    {
        $this->request->method('getParam')->willReturnCallback(
            fn ($key) => $key === 'website' ? 'base' : null
        );
        $website = $this->createMock(WebsiteInterface::class);
        $website->method('getId')->willReturn(4);
        $this->storeManager->method('getWebsite')->with('base')->willReturn($website);

        $this->configRepository->expects($this->once())
            ->method('getSurchargeTaxClassIdAtScope')
            ->with('website', 4)
            ->willReturn(0);

        $values = array_column($this->source->toOptionArray(), 'value');
        $this->assertContains('0', $values);
    }

    /**
     * A store with NO Product Tax Classes at all still does NOT get "None"
     * offered. An earlier revision made it an escape hatch, which produced a
     * worse bug: the option list offered '0' while
     * Model\Config\Backend\SurchargeTaxClass refused it, so the only
     * selectable option could not be saved and the payment section was
     * unsavable either way. The two sides must offer and accept the same set;
     * such a store disables the Surcharge Method, saves, and creates a Tax
     * Rule.
     */
    public function testNeverTaxedIsStillSuppressedWhenThereAreNoRealTaxClasses(): void
    {
        $emptySource = $this->getMockBuilder(ProductTaxClassSource::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getAllOptions'])
            ->getMock();
        $emptySource->method('getAllOptions')->with(true)->willReturn([
            ['value' => '0', 'label' => 'None'],
        ]);
        $source = new SurchargeTaxClass(
            $emptySource,
            $this->configRepository,
            $this->request,
            $this->storeManager
        );
        $this->configRepository->method('getSurchargeTaxClassIdAtScope')->willReturn(null);

        $values = array_column($source->toOptionArray(), 'value');
        $this->assertSame([''], $values);
    }

    /**
     * ...but such a store that ALREADY stores "None" keeps it, so it can still
     * save. This is the pair to the case above: suppression is driven purely
     * by the stored value, never by how many classes exist.
     */
    public function testStoredNeverTaxedSurvivesWithNoRealTaxClasses(): void
    {
        $emptySource = $this->getMockBuilder(ProductTaxClassSource::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getAllOptions'])
            ->getMock();
        $emptySource->method('getAllOptions')->with(true)->willReturn([
            ['value' => '0', 'label' => 'None'],
        ]);
        $source = new SurchargeTaxClass(
            $emptySource,
            $this->configRepository,
            $this->request,
            $this->storeManager
        );
        $this->configRepository->method('getSurchargeTaxClassIdAtScope')->willReturn(0);

        $this->assertSame(['', '0'], array_column($source->toOptionArray(), 'value'));
    }
}
