<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Plugin\Magento\Checkout\Block\LayoutProcessor;

use Magento\Checkout\Block\Checkout\LayoutProcessor;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Two\Gateway\Model\Brand\ActiveBrandResolver;
use Two\Gateway\Model\Brand\Descriptor;

/**
 * Synthesises a KO renderer-bootstrap entry for the active overlay
 * brand so its `payment/<code>/*` checkout surface is registered
 * against the brand-agnostic gateway_method renderer.
 *
 * The default Two brand is already registered via the static
 * view/frontend/web/js/view/payment/two_payment.js file shipped
 * with magento-plugin. This plugin therefore only emits a synthesis
 * entry when the resolved active brand is something other than the
 * default Two brand (i.e. when an overlay package such as the
 * future data-only magento-abn-plugin is installed and contributes
 * its own etc/brand.xml).
 *
 * Gated by the system/two_brand_synthesis/checkout_renderers/enabled
 * flag, default 0. While dormant, afterProcess is a pass-through.
 */
class SynthesiseBrandRenderers
{
    private const FLAG_PATH = 'two_brand_synthesis/checkout_renderers/enabled';

    private readonly bool $enabled;

    public function __construct(
        ScopeConfigInterface $scopeConfig,
        private readonly ActiveBrandResolver $activeBrandResolver
    ) {
        $this->enabled = $scopeConfig->isSetFlag(self::FLAG_PATH);
    }

    public function afterProcess(LayoutProcessor $subject, array $jsLayout): array
    {
        if (!$this->enabled) {
            return $jsLayout;
        }

        $active = $this->activeBrandResolver->resolve();
        if ($active->getCode() === ActiveBrandResolver::TWO_CODE) {
            return $jsLayout;
        }

        return $this->injectRenderer($jsLayout, $active);
    }

    private function injectRenderer(array $jsLayout, Descriptor $brand): array
    {
        $code = $brand->getCode();

        $jsLayout['components']['checkout']['children']['steps']['children']
            ['billing-step']['children']['payment']['children']['renders']
            ['children'][$code] = [
                'component' => 'Two_Gateway/js/view/payment/brand-registrar',
                'config' => [
                    'brandCode' => $code,
                ],
                'methods' => [
                    $code => [
                        'isBillingAddressRequired' => true,
                    ],
                ],
            ];

        return $jsLayout;
    }
}
