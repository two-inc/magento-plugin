<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Plugin\Magento\Payment\Model\Config\Reader;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Payment\Model\Config\Reader;
use Two\Gateway\Model\Brand\ActiveBrandResolver;
use Two\Gateway\Model\Brand\Loader;

/**
 * Appends a `methods` entry for each registered brand to the
 * merged payment.xml config. The shipped Two_Gateway/etc/payment.xml
 * historically declared the `two_payment` method's capability flags;
 * the v6 mechanism replaces per-module payment.xml shipping with
 * runtime synthesis driven by each module's etc/brand.xml.
 *
 * Mechanism: `Magento\Payment\Model\Config\Reader::read` (inherited
 * from `Magento\Framework\Config\Reader\Filesystem`) returns the
 * merged module config keyed by `methods` and `groups`. We append
 * to `methods` rather than mutating existing entries, so a brand
 * code that's also declared in a static payment.xml (e.g. during
 * the transition window before overlay strip-down) is left intact
 * — last-writer-wins on the merged array.
 *
 * Capability shape: keeps the synthesised entry minimal. Magento's
 * default capability surface (`MethodInterface::canRefund`, etc.)
 * lives on the method instance not in payment.xml; what payment.xml
 * uniquely contributes is the method registration that makes
 * `\Magento\Payment\Model\Config::getMethods()` enumerate the code.
 *
 * Gated by system/two_brand_synthesis/payment_xml/enabled, default 0.
 * While dormant, afterRead is a pass-through.
 */
class SynthesiseBrandMethods
{
    private const FLAG_PATH = 'two_brand_synthesis/payment_xml/enabled';

    private readonly bool $enabled;

    public function __construct(
        ScopeConfigInterface $scopeConfig,
        private readonly Loader $loader
    ) {
        // Read once at construction; per design v6 §16.3 the synthesis
        // flags are cached for the request lifetime.
        $this->enabled = $scopeConfig->isSetFlag(self::FLAG_PATH);
    }

    /**
     * @param Reader $subject
     * @param array<string,mixed> $result
     * @return array<string,mixed>
     */
    public function afterRead(Reader $subject, array $result): array
    {
        if (!$this->enabled) {
            return $result;
        }

        if (!isset($result['methods']) || !is_array($result['methods'])) {
            $result['methods'] = [];
        }

        foreach ($this->loader->load() as $brand) {
            $code = $brand->getCode();
            if (isset($result['methods'][$code])) {
                // A static payment.xml in some module already declared
                // this code (e.g. legacy overlay during the transition
                // window). Don't clobber it — the merged config already
                // has whatever capability flags they shipped, and the
                // method-resolution chokepoint (Helper\Data plugin)
                // handles instance construction independently.
                continue;
            }
            $result['methods'][$code] = $this->defaultCapabilities();
        }

        return $result;
    }

    /**
     * @return array<string,mixed>
     */
    private function defaultCapabilities(): array
    {
        return [
            'allow_multiple_address' => 1,
        ];
    }
}
