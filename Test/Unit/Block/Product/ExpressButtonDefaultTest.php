<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Block\Product;

use PHPUnit\Framework\TestCase;

/**
 * TWO-25800: the opt-in switch needs an explicit disabled default.
 *
 * Without one the field has no value at default scope, and Magento's Yesno
 * source lists Yes first — so the admin form DISPLAYS Yes, and any unrelated
 * save of that section posts 1 and turns on a feature documented as off by
 * default. Nothing else catches that: the block tests pass, the field renders,
 * and the merchant is simply opted in without asking.
 */
class ExpressButtonDefaultTest extends TestCase
{
    public function testTheOptInDefaultsToDisabled(): void
    {
        $path = dirname(__DIR__, 4) . '/etc/config.xml';
        $this->assertFileExists($path);

        $this->assertStringContainsString(
            '<product_button_enabled>0</product_button_enabled>',
            (string)file_get_contents($path),
            'An opt-in with no declared default renders as Yes and is posted as 1.'
        );
    }
}
