<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Plugin\Config\Structure\Reader;

use PHPUnit\Framework\TestCase;
use Two\Gateway\Plugin\Magento\Config\Model\Config\Structure\Reader\SynthesiseBrandAdminForm;

/**
 * Regression coverage for ABN-415.
 *
 * The pre-fix code gated synthesis on
 * `system/two_brand_synthesis/admin_form/enabled` via a ScopeConfig
 * `isSetFlag` call inside `afterRead`. During a cold pod start the
 * config-cache could be mid-build at the first admin request, so
 * `isSetFlag` returned false even though `etc/config.xml` declared
 * the default as `1`. The plugin then no-op'd, the un-synthesised
 * Reader output got cached, and the ABN admin tab disappeared for
 * the lifetime of the PHP-FPM worker.
 *
 * The fix removes the flag gate entirely. This test pins the
 * constructor signature so it can't grow a ScopeConfig dependency
 * again without an explicit decision.
 */
class SynthesiseBrandAdminFormTest extends TestCase
{
    public function testConstructorTakesNoScopeConfig(): void
    {
        $ctor = (new \ReflectionClass(SynthesiseBrandAdminForm::class))->getConstructor();
        self::assertNotNull($ctor);
        $paramTypes = [];
        foreach ($ctor->getParameters() as $p) {
            $t = $p->getType();
            $paramTypes[] = $t instanceof \ReflectionNamedType ? $t->getName() : (string)$t;
        }
        self::assertNotContains(
            \Magento\Framework\App\Config\ScopeConfigInterface::class,
            $paramTypes,
            'SynthesiseBrandAdminForm must not depend on ScopeConfigInterface — '
            . 'the flag gate caused the ABN-415 cold-start cache race.'
        );
    }

    public function testNoFlagPathConstant(): void
    {
        $constants = (new \ReflectionClass(SynthesiseBrandAdminForm::class))->getConstants();
        self::assertArrayNotHasKey(
            'FLAG_PATH',
            $constants,
            'The FLAG_PATH constant was removed when the flag gate was dropped — '
            . 'its reappearance signals the regression has been reintroduced.'
        );
    }
}
