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
        $tabSortOrder = (int)$brand['tab_sort_order'];

        if ($code === '') {
            throw new \DomainException(sprintf(
                'brand.xml at %s declares a <brand> element with empty code attribute',
                $sourcePath
            ));
        }

        $terms = [];
        if (isset($brand->available_payment_terms->term)) {
            foreach ($brand->available_payment_terms->term as $term) {
                $terms[] = (int)$term;
            }
        }

        $surchargeFixedMax = null;
        if (isset($brand->surcharge_fixed_max)) {
            $surchargeFixedMax = [
                'amount' => (float)$brand->surcharge_fixed_max['amount'],
                'currency' => (string)$brand->surcharge_fixed_max['currency'],
            ];
        }

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

        $allowedCurrencies = [];
        if (isset($brand->allowed_currencies->currency)) {
            foreach ($brand->allowed_currencies->currency as $currency) {
                $allowedCurrencies[] = (string)$currency;
            }
        }

        $allowedCountries = [];
        if (isset($brand->allowed_countries->country)) {
            foreach ($brand->allowed_countries->country as $country) {
                $allowedCountries[] = (string)$country;
            }
        }

        $extraHttpHeaders = [];
        if (isset($brand->extra_http_headers->header)) {
            foreach ($brand->extra_http_headers->header as $header) {
                $extraHttpHeaders[(string)$header['name']] = (string)$header;
            }
        }

        return new Descriptor(
            $code,
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
            $terms,
            $surchargeFixedMax,
            $cspOrigins,
            (string)$brand->admin_resource,
            $moduleLabelChain,
            $allowedCurrencies,
            $allowedCountries,
            $extraHttpHeaders
        );
    }
}
