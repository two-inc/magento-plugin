<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Plugin\Magento\Config\Model\Config\Structure\Reader;

use PHPUnit\Framework\TestCase;
use Two\Gateway\Plugin\Magento\Config\Model\Config\Structure\Reader\SynthesiseBrandAdminForm;

/**
 * Regression coverage for ABN-423 M1.
 *
 * The structure cache key
 * `adminhtml::backend_system_configuration_structure` is not scope-keyed.
 * If `SynthesiseBrandAdminForm::afterRead` ever became brand-conditional
 * (filtering by `ActiveBrandResolver`, `MAGE_RUN_CODE`, or store scope),
 * the first writer would poison the cache for all subsequent readers
 * and merchants running multi-brand / multi-store installs would see
 * brand-asymmetric admin trees.
 *
 * This test pins the structural invariant that the plugin must NOT
 * depend on anything that could vary the synthesised output by request
 * scope. Filtering belongs in `Section::isVisible()` (see
 * `HidePaymentSection`), which runs per-request and never touches the
 * cache file.
 */
class BrandUnionInvariantTest extends TestCase
{
    /**
     * Constructor must depend only on scope-blind collaborators:
     * the brand Loader (filesystem-only, iterates all brand.xml),
     * Magento's Converter (deterministic XML→array), the module
     * directory resolver, and the logger.
     *
     * Adding any of the following would silently re-introduce the
     * brand-asymmetric-cache class of bug:
     *
     *   - StoreManagerInterface (active store / website)
     *   - State::getAreaCode (CLI vs HTTP)
     *   - ScopeConfigInterface (per-store config gate)
     *   - ActiveBrandResolver (active brand selection)
     *   - RequestInterface (current request scope)
     */
    public function testConstructorTakesNoScopeDependentCollaborators(): void
    {
        $ctor = (new \ReflectionClass(SynthesiseBrandAdminForm::class))->getConstructor();
        self::assertNotNull($ctor);

        $forbidden = [
            \Magento\Store\Model\StoreManagerInterface::class,
            \Magento\Framework\App\State::class,
            \Magento\Framework\App\Config\ScopeConfigInterface::class,
            \Magento\Framework\App\RequestInterface::class,
        ];
        // ActiveBrandResolver lives in the plugin itself; reference by FQN
        // string so this test still runs if the class is later renamed.
        $forbiddenSubstrings = ['ActiveBrandResolver'];

        $paramTypes = [];
        foreach ($ctor->getParameters() as $p) {
            $t = $p->getType();
            $paramTypes[] = $t instanceof \ReflectionNamedType ? $t->getName() : (string)$t;
        }

        foreach ($forbidden as $cls) {
            self::assertNotContains(
                $cls,
                $paramTypes,
                sprintf(
                    'SynthesiseBrandAdminForm must not depend on %s — '
                    . 'this would make the cached Structure brand-asymmetric. '
                    . 'Filter at Section::isVisible() instead.',
                    $cls
                )
            );
        }
        foreach ($paramTypes as $cls) {
            foreach ($forbiddenSubstrings as $needle) {
                self::assertStringNotContainsString(
                    $needle,
                    $cls,
                    sprintf(
                        'SynthesiseBrandAdminForm must not depend on %s — '
                        . 'this would make synthesis brand-conditional and '
                        . 'poison the shared Structure cache.',
                        $needle
                    )
                );
            }
        }
    }
}
