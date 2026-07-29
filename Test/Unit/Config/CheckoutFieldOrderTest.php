<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Config;

use PHPUnit\Framework\TestCase;

/**
 * The optional checkout fields render in one canonical order everywhere:
 * invoice email, purchase order number, project, department — and, for the
 * order note (which only Magento owns a toggle for), LAST.
 *
 * The same order applies in the admin pane and at checkout, and matches
 * prestashop-plugin and woocommerce-plugin. TWO-25263.
 *
 * These assertions are deliberately about RENDERED order, not source order:
 *
 *   - the admin pane is sorted by `sortOrder`, so the test sorts the parsed
 *     fields the way Magento's config Structure does and asserts on the
 *     result. Source order in the XML is irrelevant to what the merchant
 *     sees, which is exactly the trap this pins.
 *   - the checkout tile has no sort key — knockout renders the template's
 *     markup top to bottom — so there the DOM order in the .html IS the
 *     rendered order, and the test reads it in document order.
 */
class CheckoutFieldOrderTest extends TestCase
{
    /**
     * Canonical order, as config-path suffixes / field ids.
     */
    private const CANONICAL_ADMIN_ORDER = [
        'enable_invoice_emails',
        'enable_po_number',
        'enable_project',
        'enable_department',
        'enable_order_note',
    ];

    /**
     * The checkout tile's optional-field inputs, in canonical order. The
     * order note is absent: it renders in the shipping address area
     * (view/frontend/web/template/checkout/shipping/order-note.html) and only
     * appears in the tile as a fallback, so it is asserted separately below.
     */
    private const CANONICAL_CHECKOUT_ORDER = [
        'invoice_emails',
        'two_po_number',
        'two_project',
        'two_department',
    ];

    /**
     * @return array<string, array{0: string}>
     */
    public static function adminFormProvider(): array
    {
        return [
            'system.xml' => ['etc/adminhtml/system.xml'],
            'brand_form_template.xml' => ['etc/adminhtml/brand_form_template.xml'],
        ];
    }

    /**
     * @dataProvider adminFormProvider
     */
    public function testAdminPaneRendersTheCanonicalOrder(string $relativePath): void
    {
        $path = dirname(__DIR__, 3) . '/' . $relativePath;
        self::assertFileExists($path, $relativePath . ' is missing');

        $xml = simplexml_load_file($path);
        self::assertNotFalse($xml, $relativePath . ' is not parseable XML');

        $fields = $xml->xpath('//group[@id="checkout_fields"]/field');
        self::assertIsArray($fields);
        self::assertCount(
            count(self::CANONICAL_ADMIN_ORDER),
            $fields,
            'the checkout_fields group in ' . $relativePath . ' has gained or lost a field; '
            . 'decide where it belongs in the canonical order and update this test'
        );

        $bySortOrder = [];
        foreach ($fields as $field) {
            $sortOrder = (string)$field['sortOrder'];
            self::assertNotSame(
                '',
                $sortOrder,
                sprintf(
                    'field "%s" in %s has no sortOrder, so its position is undefined',
                    (string)$field['id'],
                    $relativePath
                )
            );
            self::assertArrayNotHasKey(
                $sortOrder,
                $bySortOrder,
                sprintf(
                    'fields "%s" and "%s" in %s share sortOrder %s, so their rendered '
                    . 'order is not determined by the XML',
                    $bySortOrder[$sortOrder] ?? '',
                    (string)$field['id'],
                    $relativePath,
                    $sortOrder
                )
            );
            $bySortOrder[$sortOrder] = (string)$field['id'];
        }

        // Sort the way Magento's config Structure does before rendering.
        ksort($bySortOrder, SORT_NUMERIC);

        self::assertSame(
            self::CANONICAL_ADMIN_ORDER,
            array_values($bySortOrder),
            'the rendered admin order in ' . $relativePath . ' is not the canonical '
            . 'invoice email, purchase order number, project, department, order note'
        );
    }

    public function testCheckoutTileRendersTheCanonicalOrder(): void
    {
        $path = dirname(__DIR__, 3) . '/view/frontend/web/template/payment/gateway_method.html';
        self::assertFileExists($path, 'gateway_method.html is missing');

        $markup = file_get_contents($path);
        self::assertNotFalse($markup, 'gateway_method.html is not readable');

        $positions = [];
        foreach (self::CANONICAL_CHECKOUT_ORDER as $id) {
            $offset = strpos($markup, 'id="' . $id . '"');
            self::assertNotFalse(
                $offset,
                sprintf('no input with id="%s" in the checkout tile template', $id)
            );
            $positions[$id] = $offset;
        }

        $rendered = array_keys($positions);
        asort($positions);

        self::assertSame(
            $rendered,
            array_keys($positions),
            'the checkout tile renders the optional fields out of canonical order '
            . '(knockout paints the template top to bottom, so markup order is what '
            . 'the buyer sees)'
        );
    }

    public function testOrderNoteLivesInTheShippingAreaWithAFallbackInTheTile(): void
    {
        $shipping = dirname(__DIR__, 3)
            . '/view/frontend/web/template/checkout/shipping/order-note.html';
        self::assertFileExists(
            $shipping,
            'the shipping-area order-note template is missing; the field would have '
            . 'nowhere to render outside the payment tile'
        );

        $shippingMarkup = file_get_contents($shipping);
        self::assertNotFalse($shippingMarkup);
        self::assertStringContainsString(
            '<textarea',
            $shippingMarkup,
            'the order note is a multi-line note, so it must be a textarea'
        );
        self::assertStringContainsString(
            'rows="2"',
            $shippingMarkup,
            'the order note field is double height'
        );

        $tile = file_get_contents(
            dirname(__DIR__, 3) . '/view/frontend/web/template/payment/gateway_method.html'
        );
        self::assertNotFalse($tile);

        // The fallback copy must still submit under the original key, or the
        // relay through DataAssignObserver → ComposeOrder breaks.
        self::assertStringContainsString(
            'name="payment[orderNote]"',
            $tile,
            'the tile fallback must keep submitting the note as payment[orderNote]'
        );
        self::assertStringContainsString(
            'isOrderNoteFieldInTile',
            $tile,
            'the tile copy must be gated on the fallback predicate, otherwise the '
            . 'buyer sees two order-note fields'
        );
    }
}
