<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Block\Product;

use Magento\Framework\View\Element\Template\Context;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\BrandRegistryInterface;
use Two\Gateway\Api\Config\RepositoryInterface as ConfigRepository;
use Two\Gateway\Block\Product\PromoMessage;
use Two\Gateway\Service\Merchant\ApiKeyStatus;

/**
 * TWO-25799: the product-page message is opt-in and must stay invisible
 * unless the merchant switched it on AND the payment method is active.
 */
class PromoMessageTest extends TestCase
{
    private function block(
        bool $active,
        bool $enabled,
        string $override,
        bool $keyDefinitivelyRejected = false,
        string $productName = 'Two'
    ): PromoMessage {
        $config = $this->createMock(ConfigRepository::class);
        $config->method('isActive')->willReturn($active);
        $config->method('isProductMessageEnabled')->willReturn($enabled);
        $config->method('getProductMessage')->willReturn($override);

        $brand = $this->createMock(BrandRegistryInterface::class);
        $brand->method('getProductName')->willReturn($productName);

        $apiKeyStatus = $this->createMock(ApiKeyStatus::class);
        $apiKeyStatus->method('isDefinitiveFailure')->willReturn($keyDefinitivelyRejected);

        return new PromoMessage($this->createMock(Context::class), $config, $brand, $apiKeyStatus);
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

    /**
     * A method the buyer cannot use must not advertise itself, so the same
     * api-key verdict every other buyer-facing surface applies gates this one.
     */
    public function testHiddenWhenTheApiKeyIsDefinitivelyRejected(): void
    {
        $this->assertFalse($this->block(true, true, '', true)->isVisible());
    }

    /** Only a DEFINITIVE rejection withholds; a transient outage does not. */
    public function testVisibleWhenTheApiKeyFailureIsNotDefinitive(): void
    {
        $this->assertTrue($this->block(true, true, '', false)->isVisible());
    }

    /**
     * TWO-25799: trim() leaves U+00A0, so a nonbreaking-space override used to
     * satisfy the emptiness check and render a badge with no readable message.
     *
     * @dataProvider blankOverrideProvider
     */
    public function testAWhitespaceOnlyOverrideFallsBackToTheDefault(string $override): void
    {
        $this->assertSame(
            'Buy now, receive your goods, pay your invoice later.',
            $this->block(true, true, $override)->getMessage()
        );
    }

    public static function blankOverrideProvider(): array
    {
        return [
            'ordinary spaces' => ['   '],
            'tab and newline' => ["\t\n"],
            'nonbreaking spaces' => ["\u{00A0}\u{00A0}"],
            'ideographic space' => ["\u{3000}"],
            'zero-width no-break space' => ["\u{FEFF}"],
            'mixed' => [" \u{00A0}\t\u{202F} "],
        ];
    }

    /** A real override keeps its own inner spacing. */
    public function testAnOverrideIsTrimmedButNotOtherwiseAltered(): void
    {
        $this->assertSame('Pay  us   later', $this->block(true, true, "\u{00A0} Pay  us   later \t")->getMessage());
    }

    /** The mark is a CSS background, so the brand name is its accessible name. */
    public function testBrandLabelNamesTheProduct(): void
    {
        $this->assertSame('Acme', $this->block(true, true, '', false, 'Acme')->getBrandLabel());
    }

    /** The default names no brand: the mark beside it does that. */
    public function testDefaultNamesNoBrand(): void
    {
        $this->assertStringNotContainsString('Two', $this->block(true, true, '')->getMessage());
    }
}
