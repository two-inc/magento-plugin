<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Plugin\Magento\Config\Model\Config\Structure\Reader;

use Magento\Config\Model\Config\Structure\Converter;
use Magento\Config\Model\Config\Structure\Reader;
use Magento\Framework\Module\Dir;
use Psr\Log\LoggerInterface;
use Two\Gateway\Model\Brand\Descriptor;
use Two\Gateway\Model\Brand\Loader;

/**
 * Synthesises a brand's admin Configuration surface — tab plus
 * the four canonical sections (`{prefix}_general`, `{prefix}_payment`,
 * `{prefix}_search`, `{prefix}_version`) — by stamping
 * `etc/adminhtml/brand_form_template.xml` against each brand's
 * `Brand\Descriptor` and feeding the result through
 * `Magento\Config\Model\Config\Structure\Converter::convert`. The
 * converted tabs and sections are then merged into the Structure
 * result that `Reader::read` returns.
 *
 * Double-rendering is prevented by using `afterRead` and observing
 * what's already in the Structure: synthesis only contributes
 * section/tab IDs that aren't already statically declared.
 * First-writer-wins applies per-element, so an overlay module's
 * slim suppression-only `system.xml` (the Option B mechanism)
 * merges via Magento's native merge AFTER synthesis: synthesis
 * injects the canonical surface, overlay attributes hide what
 * each brand suppresses.
 *
 * Synthesis is unconditional. The previous `system/two_brand_synthesis/
 * admin_form/enabled` flag-gate was a transition kill-switch from
 * before strip-down; it has been removed because it created a
 * cold-cache race (ABN-415): if ScopeConfig couldn't resolve the
 * flag during the first admin request after a pod restart, the
 * plugin no-op'd, the un-synthesised Reader output was cached
 * under `adminhtml::backend_system_configuration_structure`, and
 * the ABN admin tab disappeared for the lifetime of the PHP-FPM
 * worker. Always synthesising removes the race entirely; the
 * existing skip-if-already-declared logic still protects against
 * collision with static overlays for any brand that later opts
 * into a stub system.xml.
 *
 * Design v6 §3.5 verified: `brand_code` survives Converter conversion
 * at section / group / field levels (PR #160's probe). Synthesised
 * elements carry `brand_code="{code}"` so downstream code can
 * discriminate by brand when iterating Structure (e.g. brand-aware
 * admin-block headers).
 */
class SynthesiseBrandAdminForm
{
    private const TEMPLATE_RELATIVE_PATH = '/adminhtml/brand_form_template.xml';
    private const MODULE_NAME = 'Two_Gateway';

    /**
     * Cached raw template XML, lazy-loaded on first synthesis pass.
     * Cached as a string (not parsed DOM) so each brand iteration
     * gets a fresh parse with its own token substitutions; trying
     * to share a parsed DOM across substitutions would require
     * deep-cloning and renaming nodes, which is more error-prone
     * than re-parsing the substituted string.
     */
    private ?string $rawTemplate = null;

    public function __construct(
        private readonly Loader $loader,
        private readonly Converter $converter,
        private readonly Dir $moduleDir,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param Reader $subject
     * @param array<string,mixed> $result The post-Converter Structure
     *        array. Sections live at `config.system.sections.<id>`.
     * @return array<string,mixed>
     */
    public function afterRead(Reader $subject, array $result): array
    {
        try {
            $template = $this->loadTemplate();
        } catch (\Throwable $e) {
            // Template-load failure must not break admin config rendering.
            // Log and return unchanged.
            $this->logger->error(sprintf(
                '[two_brand_admin_form] cannot load template at %s: %s — synthesis skipped',
                self::TEMPLATE_RELATIVE_PATH,
                $e->getMessage()
            ));
            return $result;
        }

        $brands = $this->loader->load();
        if ($brands === []) {
            // Empty brand list at this point is unexpected — at least
            // the vanilla Two brand should be registered. Most likely
            // cause is a cold-cache race where Loader sees an empty
            // ComponentRegistrar during early bootstrap. Log loudly so
            // the regression is visible in var/log/system.log rather
            // than presenting as a silently-missing admin tab.
            $this->logger->warning(
                '[two_brand_admin_form] Loader::load() returned no brands — '
                . 'admin Configuration section synthesis skipped; '
                . 'check ComponentRegistrar paths and brand.xml registration'
            );
            return $result;
        }

        $synthesisedSections = [];
        $synthesisedTabs = [];
        $skippedSections = [];
        $skippedTabs = [];
        foreach ($brands as $brand) {
            try {
                $converted = $this->renderBrandTemplate($template, $brand);
            } catch (\Throwable $e) {
                $this->logger->error(sprintf(
                    '[two_brand_admin_form] failed to synthesise template for brand "%s": %s — leaving Structure unchanged for this brand',
                    $brand->getCode(),
                    $e->getMessage()
                ));
                continue;
            }

            $tabs = $converted['config']['system']['tabs'] ?? [];
            foreach ($tabs as $tabId => $tab) {
                if ($this->tabExistsInResult($result, (string)$tabId)) {
                    // Tab is a leaf in the converted form (id, label, sortOrder).
                    // If an overlay declared it statically, the static
                    // declaration wins. We don't deep-merge tabs.
                    $skippedTabs[] = (string)$tabId;
                    continue;
                }
                $result['config']['system']['tabs'][$tabId] = $tab;
                $synthesisedTabs[] = (string)$tabId;
            }

            $suppressedFields = $brand->getSuppressedFields();

            $sections = $converted['config']['system']['sections'] ?? [];
            foreach ($sections as $sectionId => $section) {
                $section = $this->applySuppressions(
                    $section,
                    (string)$sectionId,
                    $brand->getSectionPrefix(),
                    $suppressedFields
                );
                if ($this->sectionExistsInResult($result, (string)$sectionId)) {
                    // A static `<section id="...">` declaration from
                    // some module already landed in the merged
                    // Structure (Magento merges all modules' system.xml
                    // before our Reader::read afterRead plugin fires).
                    // Deep-merge keeps synthesised content as the base
                    // and lets static scalar attributes override per-
                    // field. The preferred mechanism for hiding fields
                    // is brand.xml `<suppressed_fields>` (handled
                    // above); deep-merge remains as a forward-compat
                    // safety net.
                    $existing = $result['config']['system']['sections'][$sectionId];
                    $result['config']['system']['sections'][$sectionId] =
                        $this->deepMergeOverlay($section, $existing);
                    $synthesisedSections[] = $sectionId . '*';
                    continue;
                }
                $result['config']['system']['sections'][$sectionId] = $section;
                $synthesisedSections[] = (string)$sectionId;
            }
        }

        // Re-sort sections by sortOrder so brand-overlay static stubs
        // landing in the merged Structure ahead of synthesised siblings
        // don't bleed PHP-array insertion order into the admin left-nav.
        // (Cheap belt-and-braces; the brand.xml `<suppressed_fields>`
        // mechanism above is the primary defence — it removes the need
        // for static section stubs in the first place.)
        if (isset($result['config']['system']['sections'])) {
            uasort(
                $result['config']['system']['sections'],
                static fn (array $a, array $b) =>
                    ((int)($a['sortOrder'] ?? 0)) <=> ((int)($b['sortOrder'] ?? 0))
            );
        }

        if ($synthesisedSections !== [] || $synthesisedTabs !== [] || $skippedSections !== [] || $skippedTabs !== []) {
            $this->logger->info(sprintf(
                '[two_brand_admin_form] synthesised tabs [%s] sections [%s]; skipped tabs [%s] sections [%s] (already statically declared)',
                implode(',', $synthesisedTabs),
                implode(',', $synthesisedSections),
                implode(',', $skippedTabs),
                implode(',', $skippedSections)
            ));
        }

        return $result;
    }

    private function loadTemplate(): string
    {
        if ($this->rawTemplate !== null) {
            return $this->rawTemplate;
        }

        $etcDir = $this->moduleDir->getDir(self::MODULE_NAME, Dir::MODULE_ETC_DIR);
        $path = $etcDir . self::TEMPLATE_RELATIVE_PATH;

        if (!is_file($path) || !is_readable($path)) {
            throw new \RuntimeException(sprintf('template not found or unreadable: %s', $path));
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new \RuntimeException(sprintf('file_get_contents returned false for %s', $path));
        }

        return $this->rawTemplate = $contents;
    }

    /**
     * Does the Structure already have a top-level section with this
     * id? Magento nests sections under config.system.sections in the
     * converted form. Look there directly rather than walking the
     * whole tree — at most one section can have a given id, and the
     * converter's path is stable across the Magento 2.4 series.
     */
    private function sectionExistsInResult(array $result, string $sectionId): bool
    {
        return isset($result['config']['system']['sections'][$sectionId]);
    }

    private function tabExistsInResult(array $result, string $tabId): bool
    {
        return isset($result['config']['system']['tabs'][$tabId]);
    }

    /**
     * Apply brand-declared `<suppressed_fields>` to a synthesised
     * section by setting showInDefault/Website/Store="0" on the
     * targeted leaf fields. Suppression paths are of the form
     * `section_suffix/group/field`; only paths whose
     * `section_suffix` matches this section (i.e. the section id
     * equals `{prefix}_{section_suffix}`) are applied.
     *
     * The targeted field remains structurally present in the
     * Structure (Magento still validates that the field exists, the
     * canonical template is the source of truth for what controls
     * are declared) but renders as hidden in the admin form.
     *
     * @param array<string,mixed> $section
     * @param string $sectionId Full section id, e.g. `abn_payment`.
     * @param string $sectionPrefix Brand's section prefix, e.g. `abn`.
     * @param string[] $suppressedPaths `section_suffix/group/field` paths.
     * @return array<string,mixed>
     */
    private function applySuppressions(
        array $section,
        string $sectionId,
        string $sectionPrefix,
        array $suppressedPaths
    ): array {
        $expectedPrefix = $sectionPrefix . '_';
        // section_suffix is everything after the brand prefix in the
        // section id, e.g. `payment` for `abn_payment`.
        if (strncmp($sectionId, $expectedPrefix, strlen($expectedPrefix)) !== 0) {
            return $section;
        }
        $sectionSuffix = substr($sectionId, strlen($expectedPrefix));
        foreach ($suppressedPaths as $path) {
            [$pathSection, $groupId, $fieldId] = array_pad(explode('/', $path), 3, '');
            if ($pathSection !== $sectionSuffix || $groupId === '' || $fieldId === '') {
                continue;
            }
            if (!isset($section['children'][$groupId]['children'][$fieldId])) {
                $this->logger->warning(sprintf(
                    '[two_brand_admin_form] suppressed_fields path "%s" did not match any field in section "%s" — ignoring',
                    $path,
                    $sectionId
                ));
                continue;
            }
            $section['children'][$groupId]['children'][$fieldId]['showInDefault'] = '0';
            $section['children'][$groupId]['children'][$fieldId]['showInWebsite'] = '0';
            $section['children'][$groupId]['children'][$fieldId]['showInStore'] = '0';
        }
        return $section;
    }

    /**
     * Recursively merge a brand-overlay's converted Structure subtree
     * (typically a slim suppression-only section from the overlay
     * module's `etc/adminhtml/system.xml`) on top of the synthesised
     * canonical section.
     *
     * Rules:
     *   - The synthesised section is the BASE — its full shape
     *     (groups, fields, attributes) is preserved unless the
     *     overlay overrides specific values.
     *   - For scalar keys (showInDefault, showInWebsite, showInStore,
     *     label, sortOrder, …), the overlay wins. This is how an
     *     overlay suppresses a field: declare it with
     *     `showInDefault="0" showInWebsite="0" showInStore="0"` in
     *     its own system.xml and the merged scalars override the
     *     synthesised defaults.
     *   - For array keys (children: groups inside section, fields
     *     inside group), recurse: missing keys from the overlay are
     *     left alone; present keys deep-merge.
     *   - The overlay CANNOT add brand-new fields/groups by this
     *     mechanism. Anything in the overlay that names a sibling
     *     under a `children` collection (groups, fields) which isn't
     *     in the synthesised section is dropped on the floor. This
     *     is intentional: the canonical template is the source of
     *     truth for what controls exist; the overlay's job is to
     *     hide, not to extend.
     *   - Overlay scalar attributes on an existing field/group
     *     (`comment`, `tooltip`, `validate`, `canRestore`, etc.) ARE
     *     accepted even if absent from the synthesised body — the
     *     "skip unknown key" rule is scoped to children collections
     *     so per-field attribute overrides land cleanly.
     *
     * @param array<string,mixed> $base
     * @param array<string,mixed> $overlay
     * @param bool $isChildrenList true when the current recursion
     *        level is inside Magento's converted `children` array
     *        (the collection of sibling groups/fields under a section
     *        or group). At that level only, unknown keys are dropped.
     * @return array<string,mixed>
     */
    private function deepMergeOverlay(array $base, array $overlay, bool $isChildrenList = false): array
    {
        foreach ($overlay as $key => $overlayValue) {
            if ($isChildrenList && !array_key_exists($key, $base)) {
                // Overlay named a sibling field/group that the
                // synthesised section doesn't declare. Skip — the
                // canonical template is the source of truth for
                // what controls exist.
                continue;
            }
            $baseValue = $base[$key] ?? null;
            if (is_array($baseValue) && is_array($overlayValue)) {
                $base[$key] = $this->deepMergeOverlay(
                    $baseValue,
                    $overlayValue,
                    $key === 'children'
                );
            } else {
                $base[$key] = $overlayValue;
            }
        }
        return $base;
    }

    /**
     * Substitute the template's tokens against a brand's Descriptor,
     * parse the result, run it through Magento's own Converter, and
     * return the full converted Structure subtree. Callers extract
     * tabs and sections from `config.system.{tabs,sections}` and
     * merge them with their own collision policy.
     */
    private function renderBrandTemplate(string $template, Descriptor $brand): array
    {
        $substituted = strtr($template, [
            '{{code}}' => $this->escapeXmlAttribute($brand->getCode()),
            '{{section_prefix}}' => $this->escapeXmlAttribute($brand->getSectionPrefix()),
            '{{provider}}' => $this->escapeXmlAttribute($brand->getProvider()),
            '{{tab_label}}' => $this->escapeXmlAttribute($brand->getTabLabel()),
            '{{tab_css_class}}' => $this->escapeXmlAttribute($brand->getTabCssClass()),
            '{{tab_sort_order}}' => (string)$brand->getTabSortOrder(),
            '{{admin_resource}}' => $this->escapeXmlAttribute($brand->getAdminResource()),
        ]);

        $dom = new \DOMDocument('1.0', 'UTF-8');
        // libxml is noisy on schema-resolution failures (the template
        // references the same xsi:noNamespaceSchemaLocation as a real
        // system.xml). Buffer + clear so a transient resolution miss
        // doesn't pollute the PHP error log.
        $prevUseErrors = libxml_use_internal_errors(true);
        try {
            if (!$dom->loadXML($substituted)) {
                $errors = array_map(fn ($e) => trim($e->message), libxml_get_errors());
                throw new \RuntimeException(sprintf(
                    'template XML did not parse after substitution: %s',
                    implode(' | ', $errors)
                ));
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($prevUseErrors);
        }

        $converted = $this->converter->convert($dom);
        if (!isset($converted['config']['system'])) {
            throw new \RuntimeException(sprintf(
                'converter output has no config.system root for brand "%s"',
                $brand->getCode()
            ));
        }
        return $converted;
    }

    /**
     * Defensive escape for values that land inside double-quoted XML
     * attributes after substitution. Brand-supplied strings come from
     * etc/brand.xml (install-time, trusted) but a `&` in a Provider
     * name (e.g. "Smith & Co.") would otherwise corrupt the XML.
     */
    private function escapeXmlAttribute(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
