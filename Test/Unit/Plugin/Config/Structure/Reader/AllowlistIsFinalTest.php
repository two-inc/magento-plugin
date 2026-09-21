<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Plugin\Config\Structure\Reader;

use PHPUnit\Framework\TestCase;
use Two\Gateway\Plugin\Magento\Config\Model\Config\Structure\Reader\SynthesiseBrandAdminForm;

/**
 * The allowlist is the ONLY thing that decides whether an overlay sees a field.
 *
 * A static `system.xml` declaration can still refine a field the brand listed —
 * its label, its source model, its sort order — because the deep merge runs
 * first. What it cannot do is reveal a field the brand did not list: the
 * allowlist is applied after that merge, so membership is final.
 *
 * The alternative was an escape hatch, where a static `showInDefault="1"`
 * overturned the list. That is a second answer to the same question, and it
 * would make "not listed" mean "withheld unless somebody writes a section
 * stub" — which is not a rule an integrator can rely on, and not what the
 * guide would then be able to claim.
 */
class AllowlistIsFinalTest extends TestCase
{
    private function render(array $allowed, array $staticDeclaration): array
    {
        $plugin = (new \ReflectionClass(SynthesiseBrandAdminForm::class))->newInstanceWithoutConstructor();
        $logger = new \ReflectionProperty(SynthesiseBrandAdminForm::class, 'logger');
        $logger->setAccessible(true);
        $logger->setValue($plugin, $this->createMock(\Psr\Log\LoggerInterface::class));

        $call = function (string $name, array $args) use ($plugin) {
            $m = new \ReflectionMethod(SynthesiseBrandAdminForm::class, $name);
            $m->setAccessible(true);

            return $m->invoke($plugin, ...$args);
        };

        $synthesised = [
            'id' => 'acme_checkout_fields',
            'children' => [
                'display' => [
                    'id' => 'display',
                    'children' => [
                        'product_button_enabled' => [
                            'id' => 'product_button_enabled',
                            'label' => 'Show buy button on product pages',
                            'showInDefault' => '1', 'showInWebsite' => '1', 'showInStore' => '1',
                        ],
                    ],
                ],
            ],
        ];

        // Production order: deep-merge the overlay's static section, THEN
        // apply the allowlist.
        $merged = $staticDeclaration === []
            ? $synthesised
            : $call('deepMergeOverlay', [$synthesised, $staticDeclaration, false]);

        return $call('applyOverlayAllowlist', [$merged, 'acme_checkout_fields', 'acme', 'acme_payment', $allowed]);
    }

    private function declaringVisible(): array
    {
        return ['children' => ['display' => ['children' => ['product_button_enabled' => [
            'showInDefault' => '1', 'showInWebsite' => '1', 'showInStore' => '1',
        ]]]]];
    }

    /** A static declaration cannot reveal a field the brand did not list. */
    public function testAStaticDeclarationCannotDefeatTheList(): void
    {
        $out = $this->render([], $this->declaringVisible());

        $this->assertSame('0', $out['children']['display']['children']['product_button_enabled']['showInDefault']);
        $this->assertSame('0', $out['children']['display']['children']['product_button_enabled']['showInWebsite']);
        $this->assertSame('0', $out['children']['display']['children']['product_button_enabled']['showInStore']);
    }

    /** Not even when the brand lists something else entirely. */
    public function testAStaticDeclarationCannotDefeatANonEmptyList(): void
    {
        $out = $this->render(['checkout_fields/display/something_else'], $this->declaringVisible());

        $this->assertSame('0', $out['children']['display']['children']['product_button_enabled']['showInDefault']);
    }

    /**
     * What a static declaration IS still for: refining a field the brand did
     * list. The merge runs first, so the overlay's label survives — only
     * visibility is the list's to decide.
     */
    public function testAStaticDeclarationStillRefinesAListedField(): void
    {
        $out = $this->render(
            ['checkout_fields/display/product_button_enabled'],
            ['children' => ['display' => ['children' => ['product_button_enabled' => [
                'label' => 'Koop met ABN AMRO',
            ]]]]]
        );

        $field = $out['children']['display']['children']['product_button_enabled'];
        $this->assertSame('Koop met ABN AMRO', $field['label']);
        $this->assertSame('1', $field['showInDefault']);
    }
}
