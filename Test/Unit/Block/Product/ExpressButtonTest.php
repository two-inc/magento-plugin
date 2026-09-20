<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Block\Product;

use Magento\Catalog\Model\Product;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Registry;
use Magento\Framework\View\Element\Template\Context;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\BrandRegistryInterface;
use Two\Gateway\Api\Config\RepositoryInterface as ConfigRepository;
use Two\Gateway\Block\Product\ExpressButton;
use Two\Gateway\Service\Merchant\ApiKeyStatus;

/**
 * TWO-25800: the product-page button is opt-in, and it is a SEPARATE switch
 * from the promotional message — a shop may run either, both or neither.
 */
class ExpressButtonTest extends TestCase
{
    private function block(
        bool $active,
        bool $buttonEnabled,
        bool $keyDefinitivelyRejected = false,
        string $productName = 'Two',
        bool $salable = true,
        bool $readRaises = false
    ): ExpressButton {
        $config = $this->createMock(ConfigRepository::class);
        $config->method('isActive')->willReturn($active);
        if ($readRaises) {
            $config->method('isProductButtonEnabled')
                ->willThrowException(new LocalizedException(__('not available')));
        } else {
            $config->method('isProductButtonEnabled')->willReturn($buttonEnabled);
        }

        $brand = $this->createMock(BrandRegistryInterface::class);
        $brand->method('getProductName')->willReturn($productName);
        $brand->method('getCode')->willReturn('two_payment');

        $apiKeyStatus = $this->createMock(ApiKeyStatus::class);
        $apiKeyStatus->method('isDefinitiveFailure')->willReturn($keyDefinitivelyRejected);

        $product = $this->createMock(Product::class);
        $product->method('isSalable')->willReturn($salable);

        $registry = $this->createMock(Registry::class);
        $registry->method('registry')->with('current_product')->willReturn($product);

        return new ExpressButton($this->createMock(Context::class), $config, $brand, $apiKeyStatus, $registry);
    }

    /** The whole point of an opt-in: an upgrade adds no control to any storefront. */
    public function testHiddenByDefaultEvenWhenMethodIsActive(): void
    {
        $this->assertFalse($this->block(true, false)->isVisible());
    }

    public function testHiddenWhenMethodIsInactive(): void
    {
        $this->assertFalse($this->block(false, true)->isVisible());
    }

    public function testVisibleOnlyWhenActiveAndEnabled(): void
    {
        $this->assertTrue($this->block(true, true)->isVisible());
    }

    /**
     * A key Two rejected, or no key at all, means no buyer can use the method,
     * so the storefront must not offer a control that leads to it.
     */
    public function testHiddenWhenTheKeyIsDefinitivelyRejected(): void
    {
        $this->assertFalse($this->block(true, true, true)->isVisible());
    }

    /**
     * Only a DEFINITIVE rejection withholds. An outage leaves the button up
     * exactly as it leaves the method on offer (ABN-533).
     */
    public function testATransientVerdictStillRenders(): void
    {
        $this->assertTrue($this->block(true, true, false)->isVisible());
    }

    /**
     * The visible words do not name the brand — the mark does — but the brand
     * name is still emitted, because it is what keeps the control's accessible
     * name reading "Buy with <brand>".
     */
    public function testTheBrandNameIsResolvedFromTheBrand(): void
    {
        $this->assertSame('Acme', $this->block(true, true, false, 'Acme')->getBrandLabel());
    }

    /** A fragment, because a mark follows it. */
    public function testTheVisibleLabelCarriesNoBrandName(): void
    {
        $this->assertStringNotContainsString('Acme', $this->block(true, true, false, 'Acme')->getLabel());
    }

    /**
     * A button with no readable name on it would be an unattributed control
     * sitting next to add to cart, so it is withheld instead.
     */
    public function testABrandWithNoUsableNameWithholdsTheButton(): void
    {
        foreach (['', '   '] as $unusable) {
            $block = $this->block(true, true, false, $unusable);
            $this->assertSame('', $block->getBrandLabel());
            $this->assertFalse($block->isVisible());
        }
    }

    /**
     * product.info.main renders for a product Magento will not sell, where it
     * suppresses its own Add to Cart. A button there submits a form that cannot
     * succeed, so the buyer is sent into an add that fails.
     */
    public function testHiddenForAnUnsaleableProduct(): void
    {
        $this->assertFalse($this->block(true, true, false, 'Two', false)->isVisible());
    }

    /**
     * An unrecognised stored value raises at the read, per the module's
     * fail-loud standard. This gate catches it: the button goes, the product
     * page stays.
     */
    public function testAnUnrecognisedStoredValueWithholdsTheButtonNotThePage(): void
    {
        $this->assertFalse($this->block(true, true, false, 'Two', true, true)->isVisible());
    }
}
