<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Block\Product;

use PHPUnit\Framework\TestCase;

/**
 * TWO-25800: the product-page button must be placed where every product type
 * renders it, and its checkout half must actually be mounted.
 *
 * The placement trap is the one TWO-25799 hit: Magento_Catalog's
 * product/view/form.phtml renders the product_info_form_content container only
 * when the product has no options, so a block there is invisible on every
 * configurable product — which is exactly what the storefront that asked for
 * this sells. Nothing else catches it: the block's unit tests pass, the layout
 * XML is valid and the page renders without error.
 *
 * The second case is this feature's own: a button that adds to the basket but
 * never preselects the method is a button that did half its job, and the two
 * halves live in different layout files, so one can be shipped without the
 * other and everything still passes.
 */
class ExpressButtonLayoutTest extends TestCase
{
    private function layout(string $name): string
    {
        $path = dirname(__DIR__, 4) . '/view/frontend/layout/' . $name;
        $this->assertFileExists($path);

        return (string)file_get_contents($path);
    }

    public function testButtonIsNotPlacedInTheOptionsGatedContainer(): void
    {
        $this->assertStringNotContainsString(
            '"product.info.form.content"',
            $this->layout('catalog_product_view.xml'),
            'product.info.form.content renders only when the product has no options, '
            . 'so the button would vanish on every configurable product.'
        );
    }

    public function testButtonIsDeclaredInAContainerEveryProductTypeRenders(): void
    {
        $layout = $this->layout('catalog_product_view.xml');

        $this->assertStringContainsString('Two\Gateway\Block\Product\ExpressButton', $layout);
        $this->assertStringContainsString('"product.info.main"', $layout);
    }

    /**
     * Without this the button adds to the basket and the buyer then picks the
     * method by hand, which is the whole thing the button was for.
     */
    public function testTheCheckoutPreselectComponentIsMounted(): void
    {
        $this->assertStringContainsString(
            'Two_Gateway/js/view/checkout/preselect',
            $this->layout('checkout_index_index.xml')
        );
    }

    /**
     * Mounted under the sidebar, not under the payment renderer: while the
     * method is hidden its renderer is not instantiated, so a component living
     * there could not act when the method later appears.
     */
    public function testThePreselectComponentIsMountedOutsideThePaymentRenderer(): void
    {
        $layout = $this->layout('checkout_index_index.xml');
        $sidebarAt = strpos($layout, '"sidebar"');
        $preselectAt = strpos($layout, 'Two_Gateway/js/view/checkout/preselect');

        $this->assertNotFalse($sidebarAt);
        $this->assertNotFalse($preselectAt);
        $this->assertGreaterThan(
            $sidebarAt,
            $preselectAt,
            'preselect must be mounted under the always-present sidebar.'
        );
    }
}
