<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Plugin\Magento\Csp\Model\Collector;

use Magento\Csp\Api\Data\PolicyInterface;
use Magento\Csp\Model\Collector\CspWhitelistXmlCollector;
use Magento\Csp\Model\Policy\FetchPolicy;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Two\Gateway\Model\Brand\Descriptor;
use Two\Gateway\Model\Brand\Loader;

/**
 * Appends each registered brand's cspOrigins (declared in its
 * etc/brand.xml) to the merged whitelist. Magento merges policies
 * by id across modules: contributions sharing an id with an
 * existing entry override; new ids are additive. The synthesis
 * emits one FetchPolicy per `(brandCode, policyId)` pair so a
 * brand's origins land under all the policies its checkout needs
 * (form-action, base-uri, connect-src by default; configurable
 * via the brand's csp_origins/policy nodes once the schema gains
 * that field).
 *
 * Behaviour today: the default Two brand declares no cspOrigins
 * (its CSP comes from the static etc/csp_whitelist.xml shipped
 * alongside this module), so when no overlay is installed this
 * plugin emits zero entries. Overlay brand modules whose own
 * csp_whitelist.xml is dropped in the data-only release will
 * declare cspOrigins in their etc/brand.xml and the synthesis
 * picks them up here.
 *
 * Gated by system/two_brand_synthesis/csp/enabled, default 0.
 * While dormant, afterCollect is a pass-through.
 */
class SynthesiseBrandOrigins
{
    private const FLAG_PATH = 'two_brand_synthesis/csp/enabled';

    /**
     * Policies a brand's cspOrigins are seeded under unless the
     * brand declares a more specific list. Mirrors the set
     * Two_Gateway's static etc/csp_whitelist.xml ships under for
     * `*.two.inc`.
     */
    private const DEFAULT_POLICIES = ['form-action', 'base-uri', 'connect-src'];

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
     * @param CspWhitelistXmlCollector $subject
     * @param PolicyInterface[] $result
     * @return PolicyInterface[]
     */
    public function afterCollect(CspWhitelistXmlCollector $subject, array $result): array
    {
        if (!$this->enabled) {
            return $result;
        }

        foreach ($this->loader->load() as $brand) {
            foreach ($this->buildPoliciesForBrand($brand) as $policy) {
                $result[] = $policy;
            }
        }

        return $result;
    }

    /**
     * @return PolicyInterface[]
     */
    private function buildPoliciesForBrand(Descriptor $brand): array
    {
        $origins = $brand->getCspOrigins();
        if ($origins === []) {
            return [];
        }

        $policies = [];
        foreach (self::DEFAULT_POLICIES as $policyId) {
            // FetchPolicy(id, isNoneAllowed, hostSources, schemeSources,
            //   isSelfAllowed, isInlineAllowed, isEvalAllowed, ...) —
            //   constructor shape is documented in module-csp/Model/Policy/FetchPolicy.php.
            //   The minimal `host-sources` form is all we need.
            $policies[] = new FetchPolicy(
                $policyId,
                false,
                $origins
            );
        }
        return $policies;
    }
}
