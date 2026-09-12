<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Config;

use PHPUnit\Framework\TestCase;

/**
 * The Diagnostics pane is rendered from the fields synthesised out of
 * brand_form_template.xml; a field only system.xml declares is dropped by the
 * deep merge and never reaches the admin, so the two field lists must match.
 */
class DiagnosticsSectionParityTest extends TestCase
{
    /**
     * @dataProvider diagnosticsGroups
     */
    public function testEveryDeclaredFieldIsAlsoSynthesised(string $group, string $description): void
    {
        $this->assertSame(
            $this->fieldIds('etc/adminhtml/system.xml', 'two_version', $group),
            $this->fieldIds('etc/adminhtml/brand_form_template.xml', '{{section_prefix}}_version', $group),
            $description
        );
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function diagnosticsGroups(): array
    {
        return [
            'logging' => ['logging', 'the debug switch and the two log buttons'],
            'admin_controls' => ['admin_controls', 'the support-only escape hatches'],
            'general' => ['general', 'the version readout'],
            'health' => ['health', 'the health checklist and the merchant-profile refresh button'],
        ];
    }

    /**
     * @return array<int, string>
     */
    private function fieldIds(string $file, string $section, string $group): array
    {
        $xml = simplexml_load_file(__DIR__ . '/../../../' . $file);
        $fields = $xml->xpath(sprintf(
            '//section[@id="%s"]/group[@id="%s"]/field',
            $section,
            $group
        ));

        return array_map(static fn ($field): string => (string)$field['id'], $fields ?: []);
    }
}
