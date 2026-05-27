<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Plugin\Config\Structure\Reader;

use Magento\Config\Model\Config\Structure\Converter;
use Magento\Config\Model\Config\Structure\Reader;
use Magento\Framework\Module\Dir;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Two\Gateway\Model\Brand\Descriptor;
use Two\Gateway\Model\Brand\Loader;
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
 * The fix removes the flag gate entirely. This test asserts that
 * the plugin no longer takes a ScopeConfig dependency and that it
 * still runs synthesis end-to-end without one.
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

    public function testAfterReadInjectsSynthesisedSectionsWithoutAnyFlagCheck(): void
    {
        // Build a fake brand: the Two-vanilla "two" brand, matching the
        // shape Descriptor exposes to the plugin.
        $brand = $this->createMock(Descriptor::class);
        $brand->method('getCode')->willReturn('two');
        $brand->method('getSectionPrefix')->willReturn('two');
        $brand->method('getProvider')->willReturn('two');
        $brand->method('getTabLabel')->willReturn('Two');
        $brand->method('getTabCssClass')->willReturn('two-extension');
        $brand->method('getTabSortOrder')->willReturn(500);
        $brand->method('getAdminResource')->willReturn('Two_Gateway::config');
        $brand->method('getSuppressedFields')->willReturn([]);

        $loader = $this->createMock(Loader::class);
        $loader->method('load')->willReturn([$brand]);

        // Real Converter — no mocking, exercise the actual DOM→array path.
        $converter = $this->createMock(Converter::class);
        // The plugin calls $converter->convert($dom). Return a minimal
        // structure that mimics the converted template (system.sections +
        // system.tabs) so the plugin's merge path is exercised.
        $converter->method('convert')->willReturn([
            'config' => [
                'system' => [
                    'tabs' => [
                        'two_gateway' => [
                            'id' => 'two_gateway',
                            'label' => 'Two',
                            '_elementType' => 'tab',
                        ],
                    ],
                    'sections' => [
                        'two_payment' => [
                            'id' => 'two_payment',
                            'label' => 'Payment',
                            'tab' => 'two_gateway',
                            'sortOrder' => '20',
                            '_elementType' => 'section',
                            'children' => [],
                        ],
                    ],
                ],
            ],
        ]);

        // Module dir resolver — point at the real template file so
        // the plugin's `loadTemplate()` succeeds.
        $moduleDir = $this->createMock(Dir::class);
        $moduleDir->method('getDir')->willReturn(realpath(__DIR__ . '/../../../../../../etc'));

        $plugin = new SynthesiseBrandAdminForm(
            $loader,
            $converter,
            $moduleDir,
            new NullLogger()
        );

        $reader = $this->createMock(Reader::class);

        // Initial Reader output: empty system structure (no static stubs).
        $input = ['config' => ['system' => ['sections' => [], 'tabs' => []]]];

        $result = $plugin->afterRead($reader, $input);

        // The plugin must inject the synthesised content regardless
        // of any flag state — there is no flag to consult anymore.
        self::assertArrayHasKey(
            'two_payment',
            $result['config']['system']['sections'],
            'two_payment section must be present after synthesis'
        );
        self::assertSame(
            'two_gateway',
            $result['config']['system']['sections']['two_payment']['tab'] ?? null,
            'synthesised section must carry the tab attribute'
        );
        self::assertArrayHasKey(
            'two_gateway',
            $result['config']['system']['tabs'],
            'two_gateway tab must be present after synthesis'
        );
    }
}
