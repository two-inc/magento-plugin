<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Block\Product;

use PHPUnit\Framework\TestCase;

/**
 * TWO-25799: the product-page message must be placed where every product
 * type renders it.
 *
 * Magento_Catalog's product/view/form.phtml renders the
 * product_info_form_content container only when the product has no options:
 *
 *     if (!$block->hasOptions()) { getChildHtml('product_info_form_content') }
 *
 * So a block in product.info.form.content — the container that holds the
 * add-to-cart button, and the obvious place to reach for — is invisible on
 * every configurable product, which is what the storefronts asking for this
 * feature sell. Nothing else catches that: the block's own unit tests pass,
 * the layout XML is valid, and the page renders without error.
 */
class PromoMessageLayoutTest extends TestCase
{
    private function layout(): string
    {
        $path = dirname(__DIR__, 4) . '/view/frontend/layout/catalog_product_view.xml';
        $this->assertFileExists($path);

        return (string)file_get_contents($path);
    }

    public function testMessageIsNotPlacedInTheOptionsGatedContainer(): void
    {
        $this->assertStringNotContainsString(
            '"product.info.form.content"',
            $this->layout(),
            'product.info.form.content renders only when the product has no options, '
            . 'so the message would vanish on every configurable product.'
        );
    }

    public function testMessageIsPlacedInAContainerEveryProductTypeRenders(): void
    {
        $this->assertStringContainsString('"product.info.main"', $this->layout());
    }
}
