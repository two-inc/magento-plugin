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
 * every group of every section, at every nesting depth.
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
     * Terminates the "one more level" recursion: whatever shape the forms take,
     * a field the cases never reach fails here instead of passing unexamined.
     *
     * @dataProvider forms
     */
    public function testEveryDeclaredFieldSitsInAnEnumeratedGroup(string $file, string $prefix): void
    {
        $declared = $this->fieldPaths($file, $prefix);
        // An xpath that resolved nowhere would leave this comparing assertSame([], []).
        $this->assertNotEmpty($declared, sprintf('%s holds no field at all; this test\'s own xpath is stale.', $file));

        $covered = [];
        foreach (self::sectionGroups() as [$section, $group]) {
            foreach ($this->fieldIds($file, $prefix . '_' . $section, $group) as $id) {
                $covered[] = $section . '/' . $group . '/' . $id;
            }
        }
        sort($covered);

        $this->assertSame(
            $declared,
            $covered,
            sprintf('%s declares a field no case built by sectionGroups() reaches.', $file)
        );
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function forms(): array
    {
        return [
            self::VANILLA_FORM => [self::VANILLA_FORM, self::VANILLA_PREFIX],
            self::BRAND_FORM => [self::BRAND_FORM, self::BRAND_PREFIX],
        ];
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
        foreach (self::forms() as [$file, $prefix]) {
            $form = self::form($file);
            $xpath = sprintf('//section[starts-with(@id, "%s_")]', $prefix);
            $prefixed = $form->xpath($xpath) ?: [];
            $all = $form->xpath('//section') ?: [];
            if (count($prefixed) !== count($all)) {
                throw new RuntimeException(sprintf(
                    '%s declares section %s outside the "%s_" prefix, so no case here covers it.',
                    $file,
                    implode(', ', array_diff(self::ids($all), self::ids($prefixed))),
                    $prefix
                ));
            }
            foreach ($prefixed as $section) {
                $suffix = substr((string)$section['id'], strlen($prefix) + 1);
                // Depth-agnostic so a group nested in a group is compared, not skipped.
                foreach ($section->xpath('.//group[@id]') ?: [] as $group) {
                    $path = self::groupPath($group);
                    $cases[$suffix . '/' . $path] = [
                        $suffix,
                        $path,
                        sprintf('%s/%s reaches every brand, or it reaches none', $suffix, $path),
                    ];
                }
            }
        }
        ksort($cases);

        return $cases;
    }

    /**
     * @param string $group `group` or `group/nested_group`, relative to the section.
     * @return array<int, string>
     */
    private function fieldIds(string $file, string $section, string $group): array
    {
        $xpath = sprintf('//section[@id="%s"]', $section);
        foreach (explode('/', $group) as $id) {
            $xpath .= sprintf('/group[@id="%s"]', $id);
        }
        $fields = self::form($file)->xpath($xpath . '/field');

        return array_map(static fn ($field): string => (string)$field['id'], $fields ?: []);
    }

    /**
     * @return array<int, string> Sorted `section/group[/nested_group]/field`, brand prefix normalised away.
     */
    private function fieldPaths(string $file, string $prefix): array
    {
        $paths = [];
        // A <depends><field> references a field declared elsewhere rather than declaring one.
        foreach (self::form($file)->xpath('//field[not(parent::depends)]') ?: [] as $field) {
            $path = [];
            for ($node = $field; $node !== null; $node = $node->xpath('..')[0] ?? null) {
                $id = (string)$node['id'];
                if ($id === '') {
                    break;
                }
                array_unshift($path, $id);
            }
            $path[0] = substr($path[0], strlen($prefix) + 1);
            $paths[] = implode('/', $path);
        }
        sort($paths);

        return $paths;
    }

    private static function groupPath(SimpleXMLElement $group): string
    {
        $path = [];
        for ($node = $group; $node !== null && $node->getName() === 'group'; $node = $node->xpath('..')[0] ?? null) {
            array_unshift($path, (string)$node['id']);
        }

        return implode('/', $path);
    }

    /**
     * @param array<int, SimpleXMLElement> $sections
     * @return array<int, string>
     */
    private static function ids(array $sections): array
    {
        return array_map(static fn (SimpleXMLElement $section): string => (string)$section['id'], $sections);
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
