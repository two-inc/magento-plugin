<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Config;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use SimpleXMLElement;

/**
 * Every admin pane is rendered from the fields synthesised out of
 * brand_form_template.xml; a field only system.xml declares is dropped by the
 * deep merge and never reaches the admin, so the two field lists must match in
 * every group of every section.
 */
class AdminFormFieldParityTest extends TestCase
{
    private const VANILLA_FORM = 'etc/adminhtml/system.xml';
    private const BRAND_FORM = 'etc/adminhtml/brand_form_template.xml';
    private const VANILLA_PREFIX = 'two';
    private const BRAND_PREFIX = '{{section_prefix}}';

    /**
     * @dataProvider sectionGroups
     */
    public function testEveryDeclaredFieldIsAlsoSynthesised(
        string $section,
        string $group,
        string $description
    ): void {
        $declared = $this->fieldIds(self::VANILLA_FORM, self::VANILLA_PREFIX . '_' . $section, $group);
        $synthesised = $this->fieldIds(self::BRAND_FORM, self::BRAND_PREFIX . '_' . $section, $group);

        // A group empty in both forms would compare assertSame([], []) and assert nothing.
        $this->assertNotEmpty(
            array_merge($declared, $synthesised),
            sprintf('%s/%s holds no field in either form.', $section, $group)
        );

        $this->assertSame($declared, $synthesised, $description);
    }

    /**
     * Read out of both forms rather than listed here, so a section or group
     * added to either one is compared instead of silently uncovered.
     *
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function sectionGroups(): array
    {
        $cases = [];
        $forms = [self::VANILLA_FORM => self::VANILLA_PREFIX, self::BRAND_FORM => self::BRAND_PREFIX];
        foreach ($forms as $file => $prefix) {
            $xpath = sprintf('//section[starts-with(@id, "%s_")]', $prefix);
            foreach (self::form($file)->xpath($xpath) ?: [] as $section) {
                $suffix = substr((string)$section['id'], strlen($prefix) + 1);
                foreach ($section->xpath('group') ?: [] as $group) {
                    $id = $suffix . '/' . (string)$group['id'];
                    $cases[$id] = [
                        $suffix,
                        (string)$group['id'],
                        sprintf('%s reaches every brand, or it reaches none', $id),
                    ];
                }
            }
        }
        ksort($cases);

        return $cases;
    }

    /**
     * @return array<int, string>
     */
    private function fieldIds(string $file, string $section, string $group): array
    {
        $xml = simplexml_load_file(dirname(__DIR__, 3) . '/' . $file);
        $this->assertNotFalse($xml, sprintf('Cannot parse %s.', $file));

        $fields = $xml->xpath(sprintf(
            '//section[@id="%s"]/group[@id="%s"]/field',
            $section,
            $group
        ));

        return array_map(static fn ($field): string => (string)$field['id'], $fields ?: []);
    }

    private static function form(string $file): SimpleXMLElement
    {
        $xml = simplexml_load_file(dirname(__DIR__, 3) . '/' . $file);
        if ($xml === false) {
            throw new RuntimeException(sprintf('Cannot parse %s.', $file));
        }

        return $xml;
    }
}
