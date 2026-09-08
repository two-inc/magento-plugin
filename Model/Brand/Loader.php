<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Model\Brand;

use Magento\Framework\Component\ComponentRegistrar;

/**
 * Enumerates installed modules via ComponentRegistrar and loads
 * each module's etc/brand.xml into Descriptor objects.
 *
 * No OM dependency — ComponentRegistrar is populated by every
 * module's registration.php and available at any post-autoload
 * phase. Safe to call from boot-time code.
 */
class Loader
{
    /**
     * Fallback buyer-surcharge rounding steps used when a brand.xml
     * omits <surcharge_rounding_steps> (or declares it empty). Brand
     * overlays narrow this set in their own brand.xml.
     *
     * @var float[]
     */
    private const DEFAULT_ROUNDING_STEPS = [0.10, 0.50, 1.00, 5.00, 10.00];

    /** @var array<string,Descriptor>|null */
    private ?array $cache = null;

    public function __construct(
        private readonly ComponentRegistrar $componentRegistrar
    ) {
    }

    /**
     * @return array<string,Descriptor> Indexed by brand code.
     */
    public function load(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $brands = [];
        foreach ($this->componentRegistrar->getPaths(ComponentRegistrar::MODULE) as $modulePath) {
            $brandXmlPath = $modulePath . '/etc/brand.xml';
            if (!is_file($brandXmlPath)) {
                continue;
            }

            $xml = $this->loadXml($brandXmlPath);
            foreach ($xml->brand as $brandElement) {
                $descriptor = $this->buildDescriptor($brandElement, $brandXmlPath);
                if (isset($brands[$descriptor->getCode()])) {
                    throw new \DomainException(sprintf(
                        'Brand code "%s" is declared in multiple modules; '
                        . 'each brand code must be unique across the install.',
                        $descriptor->getCode()
                    ));
                }
                $brands[$descriptor->getCode()] = $descriptor;
            }
        }

        return $this->cache = $brands;
    }

    private function loadXml(string $path): \SimpleXMLElement
    {
        $previous = libxml_use_internal_errors(true);
        try {
            $xml = simplexml_load_file($path);
            if ($xml === false) {
                $errors = array_map(
                    static fn(\LibXMLError $e) => trim($e->message),
                    libxml_get_errors()
                );
                libxml_clear_errors();
                throw new \DomainException(sprintf(
                    'Failed to parse brand.xml at %s: %s',
                    $path,
                    implode('; ', $errors)
                ));
            }
            return $xml;
        } finally {
            libxml_use_internal_errors($previous);
        }
    }

    private function buildDescriptor(\SimpleXMLElement $brand, string $sourcePath): Descriptor
    {
        $code = (string)$brand['code'];
        $sectionPrefix = (string)($brand['section_prefix'] ?? '');
        $tabSortOrder = (int)$brand['tab_sort_order'];

        if ($code === '') {
            throw new \DomainException(sprintf(
                'brand.xml at %s declares a <brand> element with empty code attribute',
                $sourcePath
            ));
        }

        // Brand-driven Rounding step dropdown options. Validate at load
        // time — nothing validates brand.xsd at runtime, so a malformed
        // <step> would otherwise coerce to 0.0 and silently offer a
        // bogus option. Absent/empty falls back to the parent default.
        $roundingSteps = [];
        if (isset($brand->surcharge_rounding_steps->step)) {
            foreach ($brand->surcharge_rounding_steps->step as $step) {
                $raw = trim((string)$step);
                if (!is_numeric($raw) || (float)$raw <= 0) {
                    throw new \DomainException(sprintf(
                        'brand.xml at %s declares an invalid surcharge rounding '
                        . 'step "%s"; each <step> must be a number greater than zero.',
                        $sourcePath,
                        $raw
                    ));
                }
                $roundingSteps[] = (float)$raw;
            }
        }
        if ($roundingSteps === []) {
            $roundingSteps = self::DEFAULT_ROUNDING_STEPS;
        }
        // Dedup (0.5 == 0.50 as floats) and present ascending.
        $roundingSteps = array_values(array_unique($roundingSteps, SORT_NUMERIC));
        sort($roundingSteps, SORT_NUMERIC);

        $cspOrigins = [];
        if (isset($brand->csp_origins->origin)) {
            foreach ($brand->csp_origins->origin as $origin) {
                $cspOrigins[] = (string)$origin;
            }
        }

        $moduleLabelChain = [];
        if (isset($brand->module_label_chain->module)) {
            foreach ($brand->module_label_chain->module as $module) {
                $moduleLabelChain[] = [
                    'label' => (string)$module['label'],
                    'module' => (string)$module,
                ];
            }
        }

        $extraHttpHeaders = [];
        if (isset($brand->extra_http_headers->header)) {
            foreach ($brand->extra_http_headers->header as $header) {
                $extraHttpHeaders[(string)$header['name']] = (string)$header;
            }
        }

        $suppressedFields = [];
        if (isset($brand->suppressed_fields->field)) {
            foreach ($brand->suppressed_fields->field as $field) {
                $suppressedFields[] = (string)$field['path'];
            }
        }

        $intentApprovedNoticeEnabled = $this->readNoticeSwitch(
            $brand,
            'intent_approved_notice_enabled',
            $sourcePath
        );
        $intentApprovedNotice = $this->readNoticeCopy($brand, 'intent_approved_notice');
        $intentDeclinedNotice = $this->readNoticeCopy($brand, 'intent_declined_notice');

        // A declared switch decides. Otherwise the notice renders if
        // non-blank declined copy asked for it OR the approved switch is
        // on, so an overlay predating the declined elements — approved
        // switch only — still suppresses both.
        $intentDeclinedNoticeEnabled = isset($brand->intent_declined_notice_enabled)
            ? $this->readNoticeSwitch($brand, 'intent_declined_notice_enabled', $sourcePath)
            : ($intentDeclinedNotice !== null || $intentApprovedNoticeEnabled);

        $inlineTermFees = true;
        if (isset($brand->inline_term_fees)) {
            $inlineTermFees = filter_var(
                (string)$brand->inline_term_fees,
                FILTER_VALIDATE_BOOLEAN,
                FILTER_NULL_ON_FAILURE
            ) ?? true;
        }

        return new Descriptor(
            $code,
            $sectionPrefix,
            $tabSortOrder,
            (string)$brand->provider,
            (string)($brand->provider_full_name ?? ''),
            (string)$brand->product_name,
            (string)$brand->tab_label,
            (string)($brand->tab_css_class ?? ''),
            (string)$brand->checkout_url_template,
            (string)($brand->brand_tag ?? ''),
            (string)($brand->sign_up_url ?? ''),
            (string)($brand->documentation_url ?? ''),
            (string)$brand->api_base_url,
            $cspOrigins,
            (string)$brand->admin_resource,
            $moduleLabelChain,
            $extraHttpHeaders,
            $suppressedFields,
            $inlineTermFees,
            (string)($brand->checkout_subtitle ?? ''),
            $roundingSteps,
            $intentApprovedNotice,
            $intentApprovedNoticeEnabled,
            trim((string)($brand->about_url ?? '')),
            trim((string)($brand->checkout_subtitle_faq_url ?? '')),
            $intentDeclinedNotice,
            $intentDeclinedNoticeEnabled
        );
    }

    /**
     * Duplicates brand.xsd's enumeration because nothing validates
     * brand.xsd in production mode.
     */
    private function readNoticeSwitch(
        \SimpleXMLElement $brand,
        string $element,
        string $sourcePath
    ): bool {
        if (!isset($brand->{$element})) {
            return true;
        }

        $raw = trim((string)$brand->{$element});
        if ($raw !== 'true' && $raw !== 'false') {
            throw new \DomainException(sprintf(
                'brand.xml at %s declares an invalid <%s> value "%s"; it must '
                . 'be exactly "true" or "false".',
                $sourcePath,
                $element,
                $raw
            ));
        }

        return $raw === 'true';
    }

    /**
     * A visually-blank element is inert, never an off switch — TWO-25218
     * superseded that three-state contract. \pZ and \p{Cf} so a
     * copy-pasted non-breaking or zero-width space, both of which
     * trim() keeps, cannot become a template that renders as an empty
     * notice.
     */
    private function readNoticeCopy(\SimpleXMLElement $brand, string $element): ?string
    {
        $raw = (string)($brand->{$element} ?? '');
        // An unreadable subject is treated as blank, the safe direction;
        // the parser rejects malformed UTF-8 first, so this is unreachable.
        $copy = preg_replace('/^[\pZ\p{Cf}\s]+|[\pZ\p{Cf}\s]+$/u', '', $raw) ?? '';

        return $copy === '' ? null : $copy;
    }
}
