<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Model\Config\Source;

use Magento\Framework\App\RequestInterface;
use Magento\Store\Api\Data\GroupInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Api\Data\WebsiteInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Tax\Model\TaxClass\Source\Product as ProductTaxClassSource;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\Config\RepositoryInterface as ConfigRepository;
use Two\Gateway\Model\Config\Source\SurchargeTaxClass;
use Two\Gateway\Service\Order\SurchargeTaxCalculator;

/**
 * The surcharge tax treatment selector never auto-defaults: first
 * option is always the unselected placeholder, and the deprecated
 * "Custom" flat-rate treatment only appears for merchants with a
 * genuinely pre-existing legacy rate value.
 *
 * Never-taxed treatments are absent unconditionally (TWO-25279): core
 * "None" and the class the plugin used to provision. No grandfathering —
 * a scope already storing one is failed loud on, not offered it again.
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
            // The class the plugin used to provision: a normal
            // merchant-side row, recognisable only by its name.
            ['value' => '4', 'label' => SurchargeTaxCalculator::NO_TAX_CLASS_NAME],
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
        $this->configRepository->method('hasCustomSurchargeTaxRate')->willReturn(false);
        $options = $this->source->toOptionArray();

        $this->assertSame('', $options[0]['value']);
        $this->assertSame('-- Select surcharge tax treatment --', (string)$options[0]['label']);
    }

    public function testCustomOptionHiddenWhenNoLegacyRateExists(): void
    {
        $this->configRepository->method('hasCustomSurchargeTaxRate')->willReturn(false);
        $values = array_column($this->source->toOptionArray(), 'value');

        $this->assertNotContains(SurchargeTaxClass::CUSTOM, $values);
        $this->assertSame(['', '2'], $values);
    }

    /**
     * Core "None" is a platform default, not a rule the merchant set up, and
     * selecting it silently means "never taxed, anywhere". Removed outright —
     * there is no grandfathering, so a scope already storing it does NOT get
     * it back; it is failed loud on instead (see the field's frontend model).
     */
    public function testCoreNoneIsNeverOffered(): void
    {
        $this->configRepository->method('hasCustomSurchargeTaxRate')->willReturn(false);

        $this->assertNotContains('0', array_column($this->source->toOptionArray(), 'value'));
    }

    /**
     * The class the plugin used to provision is the same problem wearing a
     * nicer name, and its id is merchant-specific, so it can only be matched
     * by name.
     */
    public function testThePluginProvisionedNoTaxClassIsNeverOffered(): void
    {
        $this->configRepository->method('hasCustomSurchargeTaxRate')->willReturn(false);
        $options = $this->source->toOptionArray();

        $this->assertNotContains('4', array_column($options, 'value'));
        $this->assertNotContains(
            SurchargeTaxCalculator::NO_TAX_CLASS_NAME,
            array_map('strval', array_column($options, 'label'))
        );
    }

    /**
     * A merchant class that merely CONTAINS the name is a different class and
     * must survive — the check is equality, not a substring match.
     */
    public function testAMerchantClassWithASimilarNameSurvives(): void
    {
        $delegate = $this->getMockBuilder(ProductTaxClassSource::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getAllOptions'])
            ->getMock();
        $delegate->method('getAllOptions')->with(true)->willReturn([
            ['value' => '0', 'label' => 'None'],
            ['value' => '5', 'label' => SurchargeTaxCalculator::NO_TAX_CLASS_NAME . ' (legacy)'],
        ]);
        $source = new SurchargeTaxClass(
            $delegate,
            $this->configRepository,
            $this->request,
            $this->storeManager
        );
        $this->configRepository->method('hasCustomSurchargeTaxRate')->willReturn(false);

        $this->assertSame(['', '5'], array_column($source->toOptionArray(), 'value'));
    }

    public function testCustomOptionShownWhenLegacyRateExists(): void
    {
        $this->configRepository->method('hasCustomSurchargeTaxRate')->willReturn(true);
        $values = array_column($this->source->toOptionArray(), 'value');

        $this->assertSame(['', SurchargeTaxClass::CUSTOM, '2'], $values);
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
            ->method('hasCustomSurchargeTaxRate')
            ->with(7)
            ->willReturn(true);

        $values = array_column($this->source->toOptionArray(), 'value');
        $this->assertContains(SurchargeTaxClass::CUSTOM, $values);
    }

    public function testExistenceCheckResolvesWebsiteScopeViaDefaultStore(): void
    {
        $this->request->method('getParam')->willReturnCallback(
            fn ($key) => $key === 'website' ? 'base' : null
        );
        $website = $this->createMock(WebsiteInterface::class);
        $website->method('getDefaultGroupId')->willReturn(3);
        $group = $this->createMock(GroupInterface::class);
        $group->method('getDefaultStoreId')->willReturn(9);
        $this->storeManager->method('getWebsite')->with('base')->willReturn($website);
        $this->storeManager->method('getGroup')->with(3)->willReturn($group);

        $this->configRepository->expects($this->once())
            ->method('hasCustomSurchargeTaxRate')
            ->with(9)
            ->willReturn(true);

        $values = array_column($this->source->toOptionArray(), 'value');
        $this->assertContains(SurchargeTaxClass::CUSTOM, $values);
    }
}
