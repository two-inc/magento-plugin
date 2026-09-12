<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Model\Config\Comment;

use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\BrandRegistryInterface;
use Two\Gateway\Model\Config\Comment\CustomHeaders;

/**
 * The header table's admin help text. It names the active brand at render
 * time, so an overlay brand needs no override file and one catalogue row
 * carries the translation for all of them.
 */
class CustomHeadersTest extends TestCase
{
    private function commentFor(string $productName): string
    {
        $brandRegistry = $this->createMock(BrandRegistryInterface::class);
        $brandRegistry->method('getProductName')->willReturn($productName);

        return (new CustomHeaders($brandRegistry))->getCommentText(null);
    }

    /**
     * @dataProvider brands
     */
    public function testTheActiveBrandNamesTheApiAndTheAllowlistOwner(string $productName): void
    {
        $comment = $this->commentFor($productName);

        $this->assertStringContainsString(
            sprintf('every call this store makes to the %s API', $productName),
            $comment
        );
        $this->assertStringContainsString(
            sprintf('unless %s already allows that header name on it', $productName),
            $comment
        );
        $this->assertStringNotContainsString('%1', $comment, 'every placeholder must be substituted');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function brands(): array
    {
        return [
            'base brand' => ['Two'],
            'overlay brand' => ['Acme Pay'],
        ];
    }

    /**
     * A ticked row is published to every buyer, so the caveat that says so is
     * the part of this text that cannot be lost.
     */
    public function testTheDisclosureWarningSurvives(): void
    {
        $this->assertStringContainsString(
            "published to the buyer's browser and may be read by anyone",
            $this->commentFor('Two')
        );
    }
}
