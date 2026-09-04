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
 * Regression coverage for the admin-tab-vanishes-after-cold-start
 * cache race.
 *
 * The pre-fix code gated synthesis on
 * `system/two_brand_synthesis/admin_form/enabled` via a ScopeConfig
 * `isSetFlag` call inside `afterRead`. During a cold pod start the
 * config-cache could be mid-build at the first admin request, so
 * `isSetFlag` returned false even though `etc/config.xml` declared
 * the default as `1`. The plugin then no-op'd, the un-synthesised
 * Reader output got cached, and the brand-overlay admin tab disappeared for
 * the lifetime of the PHP-FPM worker.
 *
 * The fix removes the flag gate entirely. This test pins the
 * constructor signature so it can't grow a ScopeConfig dependency
 * again without an explicit decision.
 *
 * TWO-25191 additionally deleted the now-dead
 * `two_brand_synthesis/admin_form/enabled` default from
 * `etc/config.xml` — PR #181 left it behind, and its surviving
 * comment told readers to "flip to 0 to debug" a gate that no longer
 * existed. `testConfigXmlDeclaresNoAdminFormFlag` pins that removal.
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
            . 'the flag gate caused the admin-tab cold-start cache race.'
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

    public function testConfigXmlDeclaresNoAdminFormFlag(): void
    {
        $configXml = dirname(__DIR__, 6) . '/etc/config.xml';
        self::assertFileExists($configXml);

        $xml = simplexml_load_file($configXml);
        self::assertNotFalse($xml, 'etc/config.xml must parse');

        self::assertEmpty(
            $xml->xpath('/config/default/two_brand_synthesis/admin_form'),
            'etc/config.xml must not declare two_brand_synthesis/admin_form — '
            . 'nothing reads it since the flag-gate removal (TWO-25191).'
        );
    }
}
