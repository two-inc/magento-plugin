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
        $this->assertSame(
            $this->wiring('etc/adminhtml/system.xml', 'two', $slot),
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
        $xml = simplexml_load_file(__DIR__ . '/../../../' . $file);
        $wiring = [];
        foreach ($xml->xpath(sprintf('//section/group/field/%s', $slot)) ?: [] as $node) {
            $field = $node->xpath('..')[0];
            $group = $field->xpath('..')[0];
            $section = $group->xpath('..')[0];
            $suffix = substr((string)$section['id'], strlen($sectionPrefix) + 1);
            $wiring[sprintf('%s/%s/%s', $suffix, (string)$group['id'], (string)$field['id'])] = (string)$node;
        }
        ksort($wiring);

        return $wiring;
    }
}
