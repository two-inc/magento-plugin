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

/**
 * The surcharge tax treatment selector never auto-defaults: first
 * option is always the unselected placeholder, and the deprecated
 * "Custom" flat-rate treatment only appears for merchants with a
 * genuinely pre-existing legacy rate value.
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
        $this->assertSame(['', '0', '2'], $values);
    }

    public function testCustomOptionShownWhenLegacyRateExists(): void
    {
        $this->configRepository->method('hasCustomSurchargeTaxRate')->willReturn(true);
        $values = array_column($this->source->toOptionArray(), 'value');

        $this->assertSame(['', SurchargeTaxClass::CUSTOM, '0', '2'], $values);
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
