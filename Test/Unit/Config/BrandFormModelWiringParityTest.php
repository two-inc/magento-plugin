<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Config;

use PHPUnit\Framework\TestCase;

/**
 * An overlay install renders ONLY the sections synthesised out of
 * brand_form_template.xml — Plugin\Config\Structure\HidePaymentSection hides
 * the static Two ones — so a source, backend or frontend model declared in
 * system.xml alone is wired on no brand at all. ABN-497 was the surcharge tax
 * treatment's save-time guard missing from the template that way: the invariant
 * held on a Two-only install and nothing enforced it on any overlay.
 */
class BrandFormModelWiringParityTest extends TestCase
{
    /**
     * @dataProvider modelSlots
     */
    public function testEveryFieldWiresTheSameModelInBothForms(string $slot, string $description): void
    {
        $declared = $this->wiring('etc/adminhtml/system.xml', 'two', $slot);
        // assertSame([], []) passes, so an element name that resolves nowhere would leave this a permanent no-op.
        $this->assertNotEmpty($declared, sprintf('No <%s> in system.xml; this test\'s own element name is stale.', $slot));

        $this->assertSame(
            $declared,
            $this->wiring('etc/adminhtml/brand_form_template.xml', '{{section_prefix}}', $slot),
            $description
        );
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function modelSlots(): array
    {
        return [
            'backend_model' => ['backend_model', 'save-time guards — a missing one silently accepts invalid config'],
            'frontend_model' => ['frontend_model', 'field renderers, including the ones that warn on a stored value'],
            'source_model' => ['source_model', 'option lists'],
        ];
    }

    /**
     * @return array<string, string> `section/group/field` (brand prefix
     *         normalised away) => declared class.
     */
    private function wiring(string $file, string $sectionPrefix, string $slot): array
    {
        $xml = simplexml_load_file(dirname(__DIR__, 3) . '/' . $file);
        $this->assertNotFalse($xml, sprintf('Cannot parse %s.', $file));

        $wiring = [];
        // Depth-agnostic so a deeper-nested field is compared, not skipped.
        foreach ($xml->xpath(sprintf('//field/%s', $slot)) ?: [] as $node) {
            $path = [];
            for ($element = $node->xpath('..')[0] ?? null; $element !== null; $element = $element->xpath('..')[0] ?? null) {
                $id = (string)$element['id'];
                if ($id === '') {
                    break;
                }
                array_unshift($path, $id);
            }
            $expectedPrefix = $sectionPrefix . '_';
            $this->assertStringStartsWith($expectedPrefix, $path[0], $file . ' declares an unprefixed section');
            $path[0] = substr($path[0], strlen($expectedPrefix));
            $wiring[implode('/', $path)] = (string)$node;
        }
        ksort($wiring);

        return $wiring;
    }
}
