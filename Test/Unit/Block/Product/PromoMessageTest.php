<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Block\Product;

use Magento\Framework\View\Element\Template\Context;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\BrandRegistryInterface;
use Two\Gateway\Api\Config\RepositoryInterface as ConfigRepository;
use Two\Gateway\Block\Product\PromoMessage;

/**
 * TWO-25799: the product-page message is opt-in and must stay invisible
 * unless the merchant switched it on AND the payment method is active.
 */
class PromoMessageTest extends TestCase
{
    private function block(bool $active, bool $enabled, string $override): PromoMessage
    {
        $config = $this->createMock(ConfigRepository::class);
        $config->method('isActive')->willReturn($active);
        $config->method('isProductMessageEnabled')->willReturn($enabled);
        $config->method('getProductMessage')->willReturn($override);

        $brand = $this->createMock(BrandRegistryInterface::class);

        return new PromoMessage($this->createMock(Context::class), $config, $brand);
    }

    public function testHiddenByDefaultEvenWhenMethodIsActive(): void
    {
        $this->assertFalse($this->block(true, false, '')->isVisible());
    }

    public function testHiddenWhenMethodIsInactive(): void
    {
        $this->assertFalse($this->block(false, true, '')->isVisible());
    }

    public function testVisibleOnlyWhenActiveAndEnabled(): void
    {
        $this->assertTrue($this->block(true, true, '')->isVisible());
    }

    public function testMerchantOverrideWinsOverBrandDefault(): void
    {
        $this->assertSame('Pay in 30 days', $this->block(true, true, '  Pay in 30 days  ')->getMessage());
    }

    /**
     * The default is the phrase the checkout tile already uses, so the two
     * surfaces cannot drift and the wording needs no translation of its own.
     */
    public function testFallsBackToTheCheckoutTilePhrase(): void
    {
        $this->assertSame(
            'Buy now, receive your goods, pay your invoice later.',
            $this->block(true, true, '')->getMessage()
        );
    }

    /** The default names no brand: the mark beside it does that. */
    public function testDefaultNamesNoBrand(): void
    {
        $this->assertStringNotContainsString('Two', $this->block(true, true, '')->getMessage());
    }
}
