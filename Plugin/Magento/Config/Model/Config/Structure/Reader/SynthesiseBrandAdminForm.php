<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Plugin\Magento\Config\Model\Config\Structure\Reader;

use Magento\Config\Model\Config\Structure\Converter;
use Magento\Config\Model\Config\Structure\Reader;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Module\Dir;
use Psr\Log\LoggerInterface;
use Two\Gateway\Model\Brand\Descriptor;
use Two\Gateway\Model\Brand\Loader;

/**
 * Synthesises one admin Configuration section per registered brand
 * by stamping `etc/adminhtml/brand_form_template.xml` against each
 * brand's `Brand\Descriptor` and feeding the result through
 * `Magento\Config\Model\Config\Structure\Converter::convert`. The
 * converted section is then merged into the Structure result that
 * `Reader::read` returns.
 *
 * Two invariants protect against double-rendering during the
 * transition window before strip-down:
 *
 *   1. The plugin uses `afterRead`, not `beforeRead` — it observes
 *      what's already in the Structure and only contributes when a
 *      brand has no static section at the target id. First-writer-
 *      wins; an overlay module that still ships its own
 *      etc/adminhtml/system.xml stays on the static surface.
 *
 *   2. Synthesis runs only when
 *      `system/two_brand_synthesis/admin_form/enabled` is on. While
 *      the flag is 0 (the shipped default), afterRead is a literal
 *      pass-through and the template is never read.
 *
 * The strip-down PR flips this flag to 1 in lockstep with deleting
 * each overlay module's static system.xml — the moment one is gone,
 * the synthesis takes over for that brand.
 *
 * Design v6 §3.5 verified: `brand_code` survives Converter conversion
 * at section / group / field levels (PR #160's probe). Synthesised
 * elements carry `brand_code="{code}"` so downstream code can
 * discriminate by brand when iterating Structure (e.g. brand-aware
 * admin-block headers).
 */
class SynthesiseBrandAdminForm
{
    private const FLAG_PATH = 'two_brand_synthesis/admin_form/enabled';
    private const TEMPLATE_RELATIVE_PATH = '/adminhtml/brand_form_template.xml';
    private const MODULE_NAME = 'Two_Gateway';

    private readonly bool $enabled;

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
        ScopeConfigInterface $scopeConfig,
        private readonly Loader $loader,
        private readonly Converter $converter,
        private readonly Dir $moduleDir,
        private readonly LoggerInterface $logger
    ) {
        // Per design v6 §16.3, synthesis flags are cached for the
        // request lifetime; a runtime config:set requires cache:flush
        // + restart for the new value to land.
        $this->enabled = $scopeConfig->isSetFlag(self::FLAG_PATH);
    }

    /**
     * @param Reader $subject
     * @param array<string,mixed> $result The post-Converter Structure
     *        array. Sections live at `config.system.sections.<id>`.
     * @return array<string,mixed>
     */
    public function afterRead(Reader $subject, array $result): array
    {
        if (!$this->enabled) {
            return $result;
        }

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
            return $result;
        }

        $synthesised = 0;
        $skipped = [];
        foreach ($brands as $brand) {
            $sectionId = $brand->getCode();
            if ($this->sectionExistsInResult($result, $sectionId)) {
                $skipped[] = $sectionId;
                continue;
            }

            try {
                $section = $this->renderBrandSection($template, $brand);
            } catch (\Throwable $e) {
                $this->logger->error(sprintf(
                    '[two_brand_admin_form] failed to synthesise section for brand "%s": %s — leaving Structure unchanged for this brand',
                    $sectionId,
                    $e->getMessage()
                ));
                continue;
            }

            $result = $this->mergeSection($result, $sectionId, $section);
            $synthesised++;
        }

        if ($synthesised > 0 || $skipped !== []) {
            $this->logger->info(sprintf(
                '[two_brand_admin_form] synthesised %d brand section(s); skipped [%s] (already statically declared)',
                $synthesised,
                implode(',', $skipped)
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

    /**
     * Substitute the template's tokens against a brand's Descriptor,
     * parse the result, run it through Magento's own Converter, and
     * return the converted section subtree (so Magento's downstream
     * mappers see the same shape they'd see for a statically declared
     * section).
     */
    private function renderBrandSection(string $template, Descriptor $brand): array
    {
        $substituted = strtr($template, [
            '{{code}}' => $this->escapeXmlAttribute($brand->getCode()),
            '{{provider}}' => $this->escapeXmlAttribute($brand->getProvider()),
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
        $sectionId = $brand->getCode();
        $section = $converted['config']['system']['sections'][$sectionId] ?? null;
        if (!is_array($section)) {
            throw new \RuntimeException(sprintf(
                'converter output has no section at config.system.sections.%s; observed sections: [%s]',
                $sectionId,
                implode(',', array_keys($converted['config']['system']['sections'] ?? []))
            ));
        }
        return $section;
    }

    private function mergeSection(array $result, string $sectionId, array $section): array
    {
        $result['config']['system']['sections'][$sectionId] = $section;
        return $result;
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
