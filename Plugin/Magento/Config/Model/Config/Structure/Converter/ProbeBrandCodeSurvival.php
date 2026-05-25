<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Plugin\Magento\Config\Model\Config\Structure\Converter;

use Magento\Config\Model\Config\Structure\Converter;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Psr\Log\LoggerInterface;

/**
 * Diagnostic spike for design v6 §3.5.
 *
 * The admin_form synthesis layer (Layer C/3) hinges on whether
 * Magento's `Config\Structure\Converter` preserves unknown XML
 * attributes (specifically `brand_code`) on `<section>`, `<group>`
 * and `<field>` nodes when it converts the merged adminhtml/system.xml
 * DOM into the array form consumed by Structure\Data. Two possible
 * worlds:
 *
 *   A. Attribute survives — the template emits `brand_code="{{code}}"`
 *      on synthesised sections; the runtime resolves the active brand
 *      by attribute lookup. Cleanest mechanism; design v6's preferred
 *      path.
 *
 *   B. Attribute dropped — the template falls back to section-id
 *      prefixing (e.g. id `brand_{{code}}_payment_terms`); the runtime
 *      resolves by string-matching section ids. Slightly uglier but
 *      well-understood. §3.5's documented fallback.
 *
 * This probe answers the question on a real instance without
 * committing the template surface. When enabled, it clones the input
 * DOM, injects one throwaway `<section>` carrying a `brand_code`
 * attribute (with the same attribute set on a child `<group>` and
 * `<field>`), passes the clone to the real converter, reads back the
 * three corresponding result-array entries, and logs which attributes
 * survived. The probe section is then stripped from the result so the
 * Structure pipeline never sees it.
 *
 * Dormant by default. Flag-flip on staging once, tail the log, flip
 * back off. Production never runs the probe.
 *
 * Gated by `system/two_brand_synthesis/admin_form_probe/enabled`.
 */
class ProbeBrandCodeSurvival
{
    private const FLAG_PATH = 'two_brand_synthesis/admin_form_probe/enabled';

    /**
     * Distinctive sentinel id and value so the log line and any leaked
     * Structure trace are unambiguously this probe's output and never
     * collide with a real section id.
     */
    private const PROBE_SECTION_ID = 'two_brand_synth_probe_xyzzy';
    private const PROBE_GROUP_ID = 'probe_group';
    private const PROBE_FIELD_ID = 'probe_field';
    private const PROBE_BRAND_CODE_SECTION = 'PROBE_S_SENTINEL';
    private const PROBE_BRAND_CODE_GROUP = 'PROBE_G_SENTINEL';
    private const PROBE_BRAND_CODE_FIELD = 'PROBE_F_SENTINEL';

    private readonly bool $enabled;

    public function __construct(
        ScopeConfigInterface $scopeConfig,
        private readonly LoggerInterface $logger
    ) {
        $this->enabled = $scopeConfig->isSetFlag(self::FLAG_PATH);
    }

    /**
     * @param Converter $subject
     * @param callable $proceed
     * @param \DOMNode $source
     * @return array<string,mixed>
     */
    public function aroundConvert(Converter $subject, callable $proceed, \DOMNode $source): array
    {
        if (!$this->enabled) {
            return $proceed($source);
        }

        // Failure of the probe must never break admin config rendering.
        // On any error, fall through to the real converter on the
        // original source and log the diagnostic regret.
        try {
            $clone = $this->cloneSource($source);
            $injected = $this->injectProbe($clone);
            if (!$injected) {
                $this->logger->warning(
                    '[two_brand_probe] could not locate <system><sections> insertion point; '
                    . 'falling through to real converter without probe injection'
                );
                return $proceed($source);
            }
            $result = $proceed($clone);
            $this->logFindings($result);
            $this->stripProbe($result);
            return $result;
        } catch (\Throwable $e) {
            $this->logger->error(sprintf(
                '[two_brand_probe] probe raised %s: %s — falling through to unmodified convert',
                $e::class,
                $e->getMessage()
            ));
            return $proceed($source);
        }
    }

    /**
     * `$source` is the merged DOM passed from Reader. Cloning a
     * detached DOMNode loses owner-document context, so we clone the
     * owner document and re-root on its document element.
     */
    private function cloneSource(\DOMNode $source): \DOMNode
    {
        if ($source instanceof \DOMDocument) {
            $clone = new \DOMDocument($source->xmlVersion ?: '1.0', $source->encoding ?: 'UTF-8');
            $clone->appendChild($clone->importNode($source->documentElement, true));
            return $clone;
        }

        $ownerDoc = $source->ownerDocument;
        if ($ownerDoc === null) {
            // Detached node — best-effort: deep-clone in place. The
            // probe section will still be appended to the cloned node.
            return $source->cloneNode(true);
        }

        $cloneDoc = new \DOMDocument($ownerDoc->xmlVersion ?: '1.0', $ownerDoc->encoding ?: 'UTF-8');
        // Import the source node (not the document root) so the clone
        // mirrors what the converter actually receives.
        $cloneDoc->appendChild($cloneDoc->importNode($source, true));
        return $cloneDoc;
    }

    /**
     * Inject the probe under `<config><system><section id="...">`. The
     * Magento adminhtml/system.xml schema nests section under system
     * under config. We use XPath against the clone to find or create
     * the insertion point.
     *
     * @return bool true if the probe was successfully appended, false
     *              if the structure was unrecognised
     */
    private function injectProbe(\DOMNode $clone): bool
    {
        $doc = $clone instanceof \DOMDocument ? $clone : $clone->ownerDocument;
        if ($doc === null) {
            return false;
        }

        $xpath = new \DOMXPath($doc);
        $systemNodes = $xpath->query('//system');
        if ($systemNodes === false || $systemNodes->length === 0) {
            return false;
        }
        $system = $systemNodes->item(0);
        if (!$system instanceof \DOMElement) {
            return false;
        }

        $section = $doc->createElement('section');
        $section->setAttribute('id', self::PROBE_SECTION_ID);
        $section->setAttribute('translate', 'label');
        $section->setAttribute('sortOrder', '999999');
        $section->setAttribute('showInDefault', '0');
        $section->setAttribute('showInWebsite', '0');
        $section->setAttribute('showInStore', '0');
        $section->setAttribute('brand_code', self::PROBE_BRAND_CODE_SECTION);

        $group = $doc->createElement('group');
        $group->setAttribute('id', self::PROBE_GROUP_ID);
        $group->setAttribute('translate', 'label');
        $group->setAttribute('sortOrder', '10');
        $group->setAttribute('showInDefault', '0');
        $group->setAttribute('showInWebsite', '0');
        $group->setAttribute('showInStore', '0');
        $group->setAttribute('brand_code', self::PROBE_BRAND_CODE_GROUP);

        $field = $doc->createElement('field');
        $field->setAttribute('id', self::PROBE_FIELD_ID);
        $field->setAttribute('translate', 'label');
        $field->setAttribute('type', 'text');
        $field->setAttribute('sortOrder', '10');
        $field->setAttribute('showInDefault', '0');
        $field->setAttribute('showInWebsite', '0');
        $field->setAttribute('showInStore', '0');
        $field->setAttribute('brand_code', self::PROBE_BRAND_CODE_FIELD);

        $group->appendChild($field);
        $section->appendChild($group);
        $system->appendChild($section);
        return true;
    }

    /**
     * Walk the converted array and report whether the probe's
     * brand_code attributes are present at each of section / group /
     * field, with the observed value (so spurious wrong-value
     * preservation is also caught).
     */
    private function logFindings(array $result): void
    {
        $section = $this->locateProbeSection($result);
        if ($section === null) {
            $this->logger->warning(
                '[two_brand_probe] result has no entry at expected probe path — converter '
                . 'either dropped the entire section or uses a different shape than expected. '
                . 'Sampling top-level result keys: '
                . implode(',', array_keys($result))
            );
            return;
        }

        $group = $section['children'][self::PROBE_GROUP_ID]
            ?? $section['groups'][self::PROBE_GROUP_ID]
            ?? null;
        $field = null;
        if (is_array($group)) {
            $field = $group['children'][self::PROBE_FIELD_ID]
                ?? $group['fields'][self::PROBE_FIELD_ID]
                ?? null;
        }

        $sectionAttr = $section['brand_code'] ?? null;
        $groupAttr = is_array($group) ? ($group['brand_code'] ?? null) : null;
        $fieldAttr = is_array($field) ? ($field['brand_code'] ?? null) : null;

        $this->logger->info(sprintf(
            '[two_brand_probe] §3.5 attribute-survival result: '
            . 'section.brand_code=%s, group.brand_code=%s, field.brand_code=%s. '
            . 'section keys=[%s]; group keys=[%s]; field keys=[%s]',
            $this->renderObserved($sectionAttr, self::PROBE_BRAND_CODE_SECTION),
            $this->renderObserved($groupAttr, self::PROBE_BRAND_CODE_GROUP),
            $this->renderObserved($fieldAttr, self::PROBE_BRAND_CODE_FIELD),
            implode(',', array_keys($section)),
            is_array($group) ? implode(',', array_keys($group)) : '<absent>',
            is_array($field) ? implode(',', array_keys($field)) : '<absent>'
        ));
    }

    /**
     * Strip every trace of the probe from the result. The probe must
     * never leak into the Structure consumed by the admin UI: a stray
     * section with showInDefault=0 wouldn't render, but leaving it in
     * would still pollute getSections() / getTabs() iteration.
     */
    private function stripProbe(array &$result): void
    {
        $this->walkAndRemove($result, self::PROBE_SECTION_ID);
    }

    /**
     * Recurse the converted structure looking for the probe section
     * keyed by id at any depth. Magento's converter has varied the
     * exact nesting key over versions ('sections' vs 'children'),
     * so a depth-first walk is more robust than a hardcoded path.
     */
    private function locateProbeSection(array $result): ?array
    {
        $stack = [$result];
        while ($stack !== []) {
            $node = array_pop($stack);
            if (!is_array($node)) {
                continue;
            }
            if (isset($node[self::PROBE_SECTION_ID]) && is_array($node[self::PROBE_SECTION_ID])) {
                $candidate = $node[self::PROBE_SECTION_ID];
                if (($candidate['id'] ?? self::PROBE_SECTION_ID) === self::PROBE_SECTION_ID) {
                    return $candidate;
                }
            }
            foreach ($node as $child) {
                if (is_array($child)) {
                    $stack[] = $child;
                }
            }
        }
        return null;
    }

    private function walkAndRemove(array &$node, string $needle): void
    {
        foreach ($node as $key => &$value) {
            if ($key === $needle) {
                unset($node[$key]);
                continue;
            }
            if (is_array($value)) {
                $this->walkAndRemove($value, $needle);
            }
        }
    }

    private function renderObserved(mixed $observed, string $expected): string
    {
        if ($observed === null) {
            return 'DROPPED';
        }
        if ($observed === $expected) {
            return 'SURVIVED';
        }
        return sprintf('MUTATED(%s)', is_scalar($observed) ? (string)$observed : gettype($observed));
    }
}
