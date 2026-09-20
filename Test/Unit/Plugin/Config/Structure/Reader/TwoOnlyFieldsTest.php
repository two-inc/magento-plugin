<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Plugin\Config\Structure\Reader;

use PHPUnit\Framework\TestCase;
use Two\Gateway\Model\Brand\ActiveBrandResolver;
use Two\Gateway\Plugin\Magento\Config\Model\Config\Structure\Reader\SynthesiseBrandAdminForm;

/**
 * A Two-only admin control is OFFERED to Two and withheld from every other
 * brand, without that brand having to ask for it to be withheld.
 *
 * The rule exists because the alternative is an opt-out list in each overlay's
 * brand.xml: a surface added to the shared template later then appears on every
 * partner's admin until somebody notices and patches that overlay. Withholding
 * by default means the brand that forgets gets nothing, rather than the brand
 * that forgets getting somebody else's feature.
 */
class TwoOnlyFieldsTest extends TestCase
{
    private function section(): array
    {
        return [
            'id' => 'acme_checkout_fields',
            'children' => [
                'display' => [
                    'id' => 'display',
                    'children' => [
                        'product_button_enabled' => [
                            'id' => 'product_button_enabled',
                            'two_only' => '1',
                            'showInDefault' => '1',
                            'showInWebsite' => '1',
                            'showInStore' => '1',
                        ],
                        'show_about_link' => [
                            'id' => 'show_about_link',
                            'showInDefault' => '1',
                            'showInWebsite' => '1',
                            'showInStore' => '1',
                        ],
                    ],
                ],
            ],
        ];
    }

    private function withhold(array $section, string $brandCode): array
    {
        $method = new \ReflectionMethod(SynthesiseBrandAdminForm::class, 'withholdTwoOnlyFields');
        $method->setAccessible(true);

        $plugin = (new \ReflectionClass(SynthesiseBrandAdminForm::class))->newInstanceWithoutConstructor();
        $logger = new \ReflectionProperty(SynthesiseBrandAdminForm::class, 'logger');
        $logger->setAccessible(true);
        $logger->setValue($plugin, $this->createMock(\Psr\Log\LoggerInterface::class));

        return $method->invoke($plugin, $section, 'acme_checkout_fields', $brandCode);
    }


    /**
     * The three stages afterRead applies to a synthesised section, in its
     * order: the brand's own suppressions, this package's withholding, then
     * the deep merge with whatever the overlay declared statically.
     *
     * @param array<string,mixed> $overlayDeclaration what the overlay's own
     *        system.xml contributes for this section, or [] for none.
     */
    private function pipeline(
        array $section,
        string $brandCode,
        array $suppressedPaths = [],
        array $overlayDeclaration = []
    ): array {
        $plugin = (new \ReflectionClass(SynthesiseBrandAdminForm::class))->newInstanceWithoutConstructor();
        $logger = new \ReflectionProperty(SynthesiseBrandAdminForm::class, 'logger');
        $logger->setAccessible(true);
        $logger->setValue($plugin, $this->createMock(\Psr\Log\LoggerInterface::class));

        $call = function (string $name, array $args) use ($plugin) {
            $m = new \ReflectionMethod(SynthesiseBrandAdminForm::class, $name);
            $m->setAccessible(true);

            return $m->invoke($plugin, ...$args);
        };

        $section = $call('applySuppressions', [$section, 'abn_checkout_fields', 'abn', $suppressedPaths]);
        $section = $call('withholdTwoOnlyFields', [$section, 'abn_checkout_fields', $brandCode]);

        if ($overlayDeclaration !== []) {
            $section = $call('deepMergeOverlay', [$section, $overlayDeclaration, false]);
        }

        return $section;
    }

    /** What an overlay contributes by declaring the field in its own system.xml. */
    private function overlayDeclaring(string $fieldId): array
    {
        return [
            'children' => [
                'display' => [
                    'children' => [
                        $fieldId => [
                            'id' => $fieldId,
                            'showInDefault' => '1',
                            'showInWebsite' => '1',
                            'showInStore' => '1',
                        ],
                    ],
                ],
            ],
        ];
    }

    /** An overlay is not offered the control at all. */
    public function testAnOverlayBrandDoesNotSeeATwoOnlyField(): void
    {
        $out = $this->withhold($this->section(), 'acme_payment');
        $field = $out['children']['display']['children']['product_button_enabled'];

        $this->assertSame('0', $field['showInDefault']);
        $this->assertSame('0', $field['showInWebsite']);
        $this->assertSame('0', $field['showInStore']);
    }

    /**
     * Hidden, never deleted: Magento validates that a configured path has a
     * field behind it, so removing the node breaks the section rather than
     * quietening it.
     */
    public function testTheFieldStaysStructurallyPresent(): void
    {
        $out = $this->withhold($this->section(), 'acme_payment');

        $this->assertArrayHasKey('product_button_enabled', $out['children']['display']['children']);
    }

    /** Two's own admin keeps it. */
    public function testTheTwoBrandKeepsTheField(): void
    {
        $out = $this->withhold($this->section(), ActiveBrandResolver::TWO_CODE);
        $field = $out['children']['display']['children']['product_button_enabled'];

        $this->assertSame('1', $field['showInDefault']);
    }

    /** A field carrying no marker is nobody's business here. */
    public function testAnUnmarkedFieldIsUntouchedOnEveryBrand(): void
    {
        foreach (['acme_payment', ActiveBrandResolver::TWO_CODE] as $code) {
            $out = $this->withhold($this->section(), $code);

            $this->assertSame('1', $out['children']['display']['children']['show_about_link']['showInDefault']);
        }
    }

    /** The marker is this module's vocabulary and never reaches Magento. */
    public function testTheMarkerIsStrippedOnEveryBrand(): void
    {
        foreach (['acme_payment', ActiveBrandResolver::TWO_CODE] as $code) {
            $out = $this->withhold($this->section(), $code);

            $this->assertArrayNotHasKey(
                'two_only',
                $out['children']['display']['children']['product_button_enabled']
            );
        }
    }

    /**
     * The product-page surfaces are Two-only because this package paints their
     * brand mark for its own code alone. Pinned so the marker cannot be
     * dropped from the template without a test saying so — losing it is
     * silent, and what it loses is every partner's admin gaining a control
     * they never designed.
     */
    public function testTheProductPageFieldsAreMarkedInTheTemplate(): void
    {
        $template = file_get_contents(__DIR__ . '/../../../../../../etc/adminhtml/brand_form_template.xml');
        $this->assertNotFalse($template, 'brand_form_template.xml is not readable');

        $xml = new \SimpleXMLElement($template);

        foreach (['product_message_enabled', 'product_message', 'product_button_enabled'] as $fieldId) {
            $matches = $xml->xpath(sprintf('//field[@id="%s"]', $fieldId));
            $this->assertNotEmpty($matches, sprintf('%s is missing from the template', $fieldId));
            $this->assertSame(
                '1',
                (string)$matches[0]['two_only'],
                sprintf('%s must stay marked two_only', $fieldId)
            );
        }
    }

    /**
     * Both product-page switches read as OFF on a brand that declares no
     * default. Magento renders a select with no stored value as its FIRST
     * option, and this package can only declare a default under its own
     * payment code — so with core's Yesno, whose first option is Yes, an
     * overlay's form displays Yes for a feature that is off.
     */
    public function testBothProductPageSwitchesUseTheOffByDefaultSource(): void
    {
        $template = file_get_contents(__DIR__ . '/../../../../../../etc/adminhtml/brand_form_template.xml');
        $xml = new \SimpleXMLElement((string)$template);

        foreach (['product_message_enabled', 'product_button_enabled'] as $fieldId) {
            $matches = $xml->xpath(sprintf('//field[@id="%s"]/source_model', $fieldId));
            $this->assertNotEmpty($matches, sprintf('%s declares no source_model', $fieldId));
            $this->assertSame(
                'Two\\Gateway\\Model\\Config\\Source\\OffByDefaultYesno',
                trim((string)$matches[0]),
                sprintf('%s must not fall back to a Yes-first source', $fieldId)
            );
        }
    }

    /**
     * THE ESCAPE HATCH. Withholding is this package's default, not a veto: an
     * overlay that declares the field in its own system.xml gets it, because
     * the deep merge runs last and a static scalar wins per field.
     *
     * This is the property a branded partner builds against, and the one a
     * refactor could take away silently — moving the merge ahead of the
     * withholding leaves every other case here green while the hatch stops
     * working.
     */
    public function testAnOverlayDeclaringTheFieldGetsItBackDespiteTheMarker(): void
    {
        $out = $this->pipeline(
            $this->section(),
            'abn_payment',
            [],
            $this->overlayDeclaring('product_button_enabled')
        );

        $this->assertSame('1', $out['children']['display']['children']['product_button_enabled']['showInDefault']);
        $this->assertSame('1', $out['children']['display']['children']['product_button_enabled']['showInWebsite']);
        $this->assertSame('1', $out['children']['display']['children']['product_button_enabled']['showInStore']);
    }

    /**
     * ABN's exact position: the field is BOTH suppressed by its own brand.xml
     * and marked two_only here. Declaring it still wins, because the merge
     * runs after both — so opting in is ONE act, declaring the field, and
     * withdrawing the suppression entry is tidying rather than a second
     * required step.
     *
     * Stated as a test because the instruction in the overlay's own brand.xml
     * is what a maintainer will follow, and an instruction that disagrees with
     * the code sends them looking for a fault that is not there.
     */
    public function testDeclaringWinsEvenWhileTheBrandStillSuppressesTheField(): void
    {
        $out = $this->pipeline(
            $this->section(),
            'abn_payment',
            ['checkout_fields/display/product_button_enabled'],
            $this->overlayDeclaring('product_button_enabled')
        );

        $this->assertSame('1', $out['children']['display']['children']['product_button_enabled']['showInDefault']);
    }

    /**
     * And without the declaration, suppression plus withholding compose to the
     * same answer either mechanism gives alone: hidden, field still present.
     */
    public function testSuppressionAndWithholdingComposeToHidden(): void
    {
        $out = $this->pipeline(
            $this->section(),
            'abn_payment',
            ['checkout_fields/display/product_button_enabled']
        );

        $this->assertSame('0', $out['children']['display']['children']['product_button_enabled']['showInDefault']);
        $this->assertArrayHasKey('product_button_enabled', $out['children']['display']['children']);
    }

    /**
     * The order guard for the case above. The behaviour tests drive the stages
     * themselves, so only this one can fail if production stops running them
     * in that order — and the escape hatch depends entirely on the merge being
     * last.
     */
    public function testAfterReadWithholdsBeforeItMergesTheOverlay(): void
    {
        $source = file_get_contents(
            __DIR__ . '/../../../../../../Plugin/Magento/Config/Model/Config/Structure/Reader/SynthesiseBrandAdminForm.php'
        );
        $this->assertNotFalse($source);

        $withhold = strpos((string)$source, '$this->withholdTwoOnlyFields(');
        $merge = strpos((string)$source, '$this->deepMergeOverlay($section, $existing)');

        $this->assertNotFalse($withhold, 'afterRead no longer calls withholdTwoOnlyFields');
        $this->assertNotFalse($merge, 'afterRead no longer deep-merges the overlay declaration');
        $this->assertLessThan(
            $merge,
            $withhold,
            'the overlay deep-merge must run AFTER withholding, or declaring a two_only field cannot win'
        );
    }
}
