<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Config;

use PHPUnit\Framework\TestCase;

/**
 * Every optional checkout field ships enabled out of the box.
 *
 * These are *declared* defaults in etc/config.xml, which Magento consults
 * only when no core_config_data row exists for the path and scope. A
 * merchant who explicitly saved "No" has a row holding `0`, and that row
 * still wins — so this test pins the out-of-box state without saying
 * anything about shops that made an explicit choice.
 *
 * The values are asserted against the shipped XML rather than a runtime
 * scope config so the test fails when someone edits config.xml, which is
 * the change worth catching.
 */
class CheckoutFieldDefaultsTest extends TestCase
{
    /**
     * Optional checkout-field toggles, as grouped under "Checkout fields"
     * in etc/adminhtml/system.xml.
     */
    private const OPTIONAL_FIELD_FLAGS = [
        'enable_department',
        'enable_project',
        'enable_po_number',
        'enable_order_note',
        'enable_invoice_emails',
        // TWO-25386: both default to enabled — display_tooltips preserves
        // Magento's prior (unconditional) behaviour.
        'show_about_link',
        'display_tooltips',
    ];

    /** @var \SimpleXMLElement */
    private $twoPaymentDefaults;

    protected function setUp(): void
    {
        $path = dirname(__DIR__, 3) . '/etc/config.xml';
        self::assertFileExists($path, 'etc/config.xml is missing');

        $xml = simplexml_load_file($path);
        self::assertNotFalse($xml, 'etc/config.xml is not parseable XML');

        $nodes = $xml->xpath('/config/default/payment/two_payment');
        self::assertIsArray($nodes);
        self::assertCount(1, $nodes, 'expected exactly one default/payment/two_payment node');

        $this->twoPaymentDefaults = $nodes[0];
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function optionalFieldFlagProvider(): array
    {
        $cases = [];
        foreach (self::OPTIONAL_FIELD_FLAGS as $flag) {
            $cases[$flag] = [$flag];
        }

        return $cases;
    }

    /**
     * @dataProvider optionalFieldFlagProvider
     */
    public function testOptionalCheckoutFieldDefaultsToEnabled(string $flag): void
    {
        $node = $this->twoPaymentDefaults->{$flag};

        self::assertTrue(
            isset($node),
            sprintf(
                '%s has no declared default. An unset flag reads as disabled, '
                . 'so the field would silently vanish from checkout.',
                $flag
            )
        );

        self::assertSame(
            '1',
            (string)$node,
            sprintf('%s must default to 1 so the field renders out of the box', $flag)
        );
    }

    /**
     * Guards the assumption the rest of this test rests on: that these five
     * flags really are the complete "Checkout fields" set, so a sixth
     * optional field added later cannot default to off unnoticed.
     */
    public function testFlagListMatchesTheAdminCheckoutFieldsGroup(): void
    {
        $path = dirname(__DIR__, 3) . '/etc/adminhtml/system.xml';
        self::assertFileExists($path, 'etc/adminhtml/system.xml is missing');

        $xml = simplexml_load_file($path);
        self::assertNotFalse($xml, 'etc/adminhtml/system.xml is not parseable XML');

        $paths = $xml->xpath(
            '/config/system/section[@id="two_checkout_fields"]/group[@id="checkout_fields"]/field/config_path'
        );
        self::assertIsArray($paths);
        self::assertNotEmpty($paths, 'no fields found in the checkout_fields admin group');

        $ids = [];
        foreach ($paths as $path) {
            $segments = explode('/', (string)$path);
            $ids[] = end($segments);
        }
        sort($ids);

        $expected = self::OPTIONAL_FIELD_FLAGS;
        sort($expected);

        self::assertSame(
            $expected,
            $ids,
            'the checkout_fields admin group and OPTIONAL_FIELD_FLAGS have drifted; '
            . 'add the new toggle to this test and give it a declared default'
        );
    }
}
