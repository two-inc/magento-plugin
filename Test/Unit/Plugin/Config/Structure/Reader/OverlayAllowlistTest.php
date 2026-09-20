<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Plugin\Config\Structure\Reader;

use PHPUnit\Framework\TestCase;
use Two\Gateway\Model\Brand\ActiveBrandResolver;
use Two\Gateway\Plugin\Magento\Config\Model\Config\Structure\Reader\SynthesiseBrandAdminForm;

/**
 * An overlay's admin form is what that overlay ASKED for, and nothing else.
 *
 * The base package declares every brand's admin surface in one template, so a
 * field added to it reaches every brand. Inverted here: a brand that is not the
 * base sees only the paths its own brand.xml names, which makes a field added
 * later dormant on that brand until the brand asks for it. Forgetting costs a
 * partner the feature rather than handing them a surface they never designed.
 *
 * Every case is stated as a property rather than a count, so adding a field to
 * the template does not rewrite this file.
 */
class OverlayAllowlistTest extends TestCase
{
    private function section(array $extraFields = []): array
    {
        $fields = [
            'product_button_enabled' => ['id' => 'product_button_enabled'],
            'show_about_link' => ['id' => 'show_about_link'],
        ];

        foreach ($extraFields as $id) {
            $fields[$id] = ['id' => $id];
        }

        foreach ($fields as $id => $field) {
            $fields[$id] = $field + [
                'showInDefault' => '1',
                'showInWebsite' => '1',
                'showInStore' => '1',
            ];
        }

        return ['id' => 'acme_checkout_fields', 'children' => ['display' => ['id' => 'display', 'children' => $fields]]];
    }

    private function apply(array $section, string $brandCode, array $allowed): array
    {
        $plugin = (new \ReflectionClass(SynthesiseBrandAdminForm::class))->newInstanceWithoutConstructor();
        $logger = new \ReflectionProperty(SynthesiseBrandAdminForm::class, 'logger');
        $logger->setAccessible(true);
        $logger->setValue($plugin, $this->createMock(\Psr\Log\LoggerInterface::class));

        $method = new \ReflectionMethod(SynthesiseBrandAdminForm::class, 'applyOverlayAllowlist');
        $method->setAccessible(true);

        return $method->invoke($plugin, $section, 'acme_checkout_fields', 'acme', $brandCode, $allowed);
    }

    private function visible(array $section, string $fieldId): bool
    {
        return ($section['children']['display']['children'][$fieldId]['showInDefault'] ?? null) === '1';
    }

    /** Dormant by default: declare nothing, get nothing. */
    public function testAnOverlayThatDeclaresNothingGetsNothing(): void
    {
        $out = $this->apply($this->section(), 'acme_payment', []);

        $this->assertFalse($this->visible($out, 'product_button_enabled'));
        $this->assertFalse($this->visible($out, 'show_about_link'));
    }

    /** And exactly what it declares — no more. */
    public function testAnOverlayGetsExactlyWhatItDeclares(): void
    {
        $out = $this->apply($this->section(), 'acme_payment', ['checkout_fields/display/show_about_link']);

        $this->assertTrue($this->visible($out, 'show_about_link'));
        $this->assertFalse($this->visible($out, 'product_button_enabled'));
    }

    /**
     * THE PROPERTY THIS EXISTS FOR. A field the base adds later is absent from
     * an overlay that has not declared it, with nobody touching the overlay —
     * which is the case an opt-out list gets wrong, because nobody remembers to
     * write the entry that would have hidden it.
     */
    public function testAFieldAddedLaterIsDormantWithoutTouchingTheOverlay(): void
    {
        $allowlistWrittenBeforeTheFieldExisted = ['checkout_fields/display/show_about_link'];

        $out = $this->apply(
            $this->section(['some_future_surface']),
            'acme_payment',
            $allowlistWrittenBeforeTheFieldExisted
        );

        $this->assertFalse($this->visible($out, 'some_future_surface'));
        $this->assertTrue($this->visible($out, 'show_about_link'));
    }

    /** The base brand is not an overlay: it keeps everything, declaring nothing. */
    public function testTheBaseBrandKeepsEveryField(): void
    {
        $out = $this->apply($this->section(['anything_at_all']), ActiveBrandResolver::TWO_CODE, []);

        $this->assertTrue($this->visible($out, 'product_button_enabled'));
        $this->assertTrue($this->visible($out, 'show_about_link'));
        $this->assertTrue($this->visible($out, 'anything_at_all'));
    }

    /**
     * Withheld, never deleted: Magento validates that a configured path has a
     * field behind it, so removing the node breaks the section instead of
     * quietening it.
     */
    public function testAWithheldFieldStaysStructurallyPresent(): void
    {
        $out = $this->apply($this->section(), 'acme_payment', []);

        $this->assertArrayHasKey('product_button_enabled', $out['children']['display']['children']);
    }

    /** A section belonging to another brand is not this brand's to filter. */
    public function testASectionOutsideTheBrandsPrefixIsUntouched(): void
    {
        $plugin = (new \ReflectionClass(SynthesiseBrandAdminForm::class))->newInstanceWithoutConstructor();
        $logger = new \ReflectionProperty(SynthesiseBrandAdminForm::class, 'logger');
        $logger->setAccessible(true);
        $logger->setValue($plugin, $this->createMock(\Psr\Log\LoggerInterface::class));
        $method = new \ReflectionMethod(SynthesiseBrandAdminForm::class, 'applyOverlayAllowlist');
        $method->setAccessible(true);

        $out = $method->invoke($plugin, $this->section(), 'other_checkout_fields', 'acme', 'acme_payment', []);

        $this->assertTrue($this->visible($out, 'product_button_enabled'));
    }

    /** No internal vocabulary reaches Magento's Structure. */
    public function testNoMarkerAttributeIsIntroduced(): void
    {
        $out = $this->apply($this->section(), 'acme_payment', ['checkout_fields/display/show_about_link']);

        foreach ($out['children']['display']['children'] as $field) {
            $this->assertArrayNotHasKey('two_only', $field);
            $this->assertArrayNotHasKey('allowed', $field);
        }
    }

    /** The template carries no leftover marker from the approach this replaces. */
    public function testTheTemplateCarriesNoTwoOnlyMarker(): void
    {
        $template = file_get_contents(__DIR__ . '/../../../../../../etc/adminhtml/brand_form_template.xml');

        $this->assertNotFalse($template);
        $this->assertStringNotContainsString('two_only', (string)$template);
    }

    /**
     * Magento nests groups arbitrarily and its Converter preserves the
     * nesting, so a walker that assumed `section > group > field` left every
     * deeper field untouched — which under an allowlist means VISIBLE, the
     * exact opposite of the rule, and invisible in review because a nested
     * field renders like any other.
     */
    public function testAFieldInsideANestedGroupIsWithheldToo(): void
    {
        $section = [
            'id' => 'acme_checkout_fields',
            'children' => [
                'display' => [
                    'id' => 'display',
                    'children' => [
                        'company_lookup' => [
                            'id' => 'company_lookup',
                            'children' => [
                                'nested_secret' => [
                                    'id' => 'nested_secret',
                                    'showInDefault' => '1',
                                    'showInWebsite' => '1',
                                    'showInStore' => '1',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $out = $this->apply($section, 'acme_payment', []);
        $nested = $out['children']['display']['children']['company_lookup']['children']['nested_secret'];

        $this->assertSame('0', $nested['showInDefault']);
        $this->assertSame('0', $nested['showInWebsite']);
        $this->assertSame('0', $nested['showInStore']);
    }

    /** A nested field its brand DID name is kept, named by its immediate parent group. */
    public function testANestedFieldTheBrandDeclaredIsKept(): void
    {
        $section = [
            'id' => 'acme_checkout_fields',
            'children' => [
                'display' => [
                    'id' => 'display',
                    'children' => [
                        'company_lookup' => [
                            'id' => 'company_lookup',
                            'children' => [
                                'wanted' => [
                                    'id' => 'wanted',
                                    'showInDefault' => '1',
                                    'showInWebsite' => '1',
                                    'showInStore' => '1',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $out = $this->apply($section, 'acme_payment', ['checkout_fields/company_lookup/wanted']);

        $this->assertSame(
            '1',
            $out['children']['display']['children']['company_lookup']['children']['wanted']['showInDefault']
        );
    }
}
