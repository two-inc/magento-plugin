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
     * The checkout tile's optional-field inputs, in canonical order — the same
     * sequence as the admin pane, with the order note last.
     */
    private const CANONICAL_CHECKOUT_ORDER = [
        'invoice_emails',
        'two_po_number',
        'two_project',
        'two_department',
        'two_order_note',
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

        // Sort the way Magento's Sorting mapper does.
        ksort($bySortOrder, SORT_NUMERIC);

        self::assertSame(
            self::CANONICAL_ADMIN_ORDER,
            array_values($bySortOrder),
            'the sortOrder sequence in ' . $relativePath . ' is not the canonical '
            . 'invoice email, purchase order number, project, department, order note'
        );

        // DOCUMENT order matters independently of sortOrder. Two_Gateway's
        // SynthesiseBrandAdminForm is an afterRead plugin on the config
        // Structure\Reader, so it re-imposes brand_form_template.xml's field
        // order AFTER Magento has sorted — meaning document order, not
        // sortOrder, is what the pane actually renders for this section.
        // Asserting both in both files is what stops the two from diverging
        // and makes the sortOrder edit meaningful rather than decorative.
        $documentOrder = [];
        foreach ($fields as $field) {
            $documentOrder[] = (string)$field['id'];
        }

        self::assertSame(
            self::CANONICAL_ADMIN_ORDER,
            $documentOrder,
            'the document order in ' . $relativePath . ' is not the canonical '
            . 'order; for this section that is what the admin pane renders, so '
            . 'renumbering sortOrder alone will not move anything'
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
            // Match the id only where it sits inside an <input> tag, so an
            // id mentioned in a comment or in prose cannot satisfy this.
            self::assertSame(
                1,
                preg_match('/<input\b[^>]*\bid="' . preg_quote($id, '/') . '"/s', $markup, $m, PREG_OFFSET_CAPTURE),
                sprintf('no <input> with id="%s" in the checkout tile template', $id)
            );
            $offset = $m[0][1];
            $positions[$id] = $offset;
        }

        // Insertion order is the canonical order (CANONICAL_CHECKOUT_ORDER was
        // iterated to build $positions); sorting by offset gives the order the
        // markup actually paints in. Comparing the two is a real assertion —
        // do not "simplify" it into comparing one of them with itself.
        $canonical = array_keys($positions);
        asort($positions);
        $rendered = array_keys($positions);

        self::assertSame(
            $canonical,
            $rendered,
            'the checkout tile renders the optional fields out of canonical order '
            . '(knockout paints the template top to bottom, so markup order is what '
            . 'the buyer sees)'
        );
    }
}
