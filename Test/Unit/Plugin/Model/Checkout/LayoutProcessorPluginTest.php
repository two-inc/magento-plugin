<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Plugin\Model\Checkout;

use Magento\Checkout\Block\Checkout\LayoutProcessor;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Model\Config\Repository;
use Two\Gateway\Plugin\Model\Checkout\LayoutProcessorPlugin;

/**
 * TWO-25288. The address step's company-number field is the company-number
 * surface the buyer actually uses, so it has to render — it spent its life
 * declared invisible while the payment tile carried a second copy.
 *
 * `disabled` is asserted too: the field must arrive locked. Company search
 * fills it from the registry for a picked company, and the storefront JS
 * unlocks it only for a company the registry holds no identifier for. A field
 * that arrived unlocked would let the buyer contradict the registry.
 */
class LayoutProcessorPluginTest extends TestCase
{
    /**
     * A `jsLayout` shaped like the real one: the shipping-address fieldset
     * already exists and already carries a field. Seeding it is what makes the
     * assertions mean something — `afterProcess()` writes through a chain of
     * array keys, so handing it `[]` autovivifies whatever path the plugin
     * happens to use and every read below would only re-read what was just
     * written. Reading out of the PRE-EXISTING fieldset makes a drifted path
     * fail.
     *
     * @return array<string,mixed>
     */
    private function seededLayout(): array
    {
        return [
            'components' => [
                'checkout' => [
                    'children' => [
                        'steps' => [
                            'children' => [
                                'shipping-step' => [
                                    'children' => [
                                        'shippingAddress' => [
                                            'children' => [
                                                'shipping-address-fieldset' => [
                                                    'children' => [
                                                        'company' => ['label' => 'Company'],
                                                    ],
                                                ],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    private function plugin(bool $isActive): LayoutProcessorPlugin
    {
        $repository = $this->createMock(Repository::class);
        $repository->method('isActive')->willReturn($isActive);

        return new LayoutProcessorPlugin($repository);
    }

    /**
     * The pre-existing fieldset's children, after the plugin has run.
     *
     * @param array<string,mixed> $jsLayout
     * @return array<string,mixed>
     */
    private function fieldset(array $jsLayout): array
    {
        return $jsLayout['components']['checkout']['children']['steps']['children']
            ['shipping-step']['children']['shippingAddress']['children']
            ['shipping-address-fieldset']['children'];
    }

    /**
     * @return array<string,mixed>
     */
    private function companyIdField(): array
    {
        $jsLayout = $this->plugin(true)->afterProcess(
            $this->createMock(LayoutProcessor::class),
            $this->seededLayout()
        );

        $fieldset = $this->fieldset($jsLayout);
        $this->assertArrayHasKey(
            'company_id',
            $fieldset,
            'company_id was not inserted into the existing shipping-address fieldset'
        );

        return $fieldset['company_id'];
    }

    public function testFieldIsInsertedIntoTheExistingShippingAddressFieldset(): void
    {
        $jsLayout = $this->plugin(true)->afterProcess(
            $this->createMock(LayoutProcessor::class),
            $this->seededLayout()
        );

        $fieldset = $this->fieldset($jsLayout);

        // The seeded sibling still there alongside the new node is what pins
        // the insertion to the real fieldset rather than a path the plugin
        // conjured on its way through.
        $this->assertSame(['company', 'company_id'], array_keys($fieldset));
        $this->assertSame(['label' => 'Company'], $fieldset['company']);
    }

    /**
     * The field renders for every buyer on the address step, so it must not
     * appear on a store that merely has the module installed with the payment
     * method switched off.
     */
    public function testNothingIsAddedWhenThePaymentMethodIsInactive(): void
    {
        $seeded = $this->seededLayout();

        $jsLayout = $this->plugin(false)->afterProcess(
            $this->createMock(LayoutProcessor::class),
            $seeded
        );

        $this->assertSame($seeded, $jsLayout);
        $this->assertArrayNotHasKey('company_id', $this->fieldset($jsLayout));
    }

    public function testCompanyNumberFieldIsRendered(): void
    {
        $this->assertTrue($this->companyIdField()['visible']);
    }

    public function testCompanyNumberFieldArrivesDisabled(): void
    {
        $this->assertTrue($this->companyIdField()['disabled']);
    }

    public function testCompanyNumberFieldIsLabelledForTheBuyer(): void
    {
        $field = $this->companyIdField();

        // Matches the label the payment tile used, and an existing translated
        // string in every shipped i18n CSV. Compared as a cast string so the
        // assertion holds for the Phrase `__()` returns and does NOT cement the
        // untranslated form.
        $this->assertSame('Company Number', (string)$field['label']);
        $this->assertSame('Company Number', (string)$field['config']['tooltip']['description']);
    }

    public function testCompanyNumberFieldKeepsItsAddressDataScope(): void
    {
        // The organisation number the Two API receives comes from the payment
        // scope, but this field is also the writer of the address attribute the
        // payment step reads back off the quote. A drifted dataScope silently
        // stops that.
        $field = $this->companyIdField();

        $this->assertSame('shippingAddress.custom_attributes.company_id', $field['dataScope']);
        $this->assertSame('shippingAddress.custom_attributes', $field['config']['customScope']);
    }

    /**
     * TWO-25288. The field must stay `visible: true` — flipping it to false
     * would pull it out of the UI-registry render tree, and
     * address-autocomplete.js resolves it through `uiRegistry.get()`. It is
     * hidden purely visually, through `additionalClasses` plus a CSS rule in
     * view/frontend/web/css/style.css, so the DOM node and its value stay
     * present.
     */
    public function testCompanyNumberFieldStaysUiRegistryVisibleButCssHidden(): void
    {
        $field = $this->companyIdField();

        $this->assertTrue($field['visible']);
        $this->assertSame('two-company-id-hidden', $field['additionalClasses']);
    }

    /**
     * A `jsLayout` shaped like seededLayout(), but with `country_id` also
     * already present carrying some sortOrder — i.e. the shape the fieldset
     * is ACTUALLY in by the time this plugin's afterProcess runs, once
     * Magento_Checkout's own `AttributeMerger::getFieldConfig()` has already
     * resolved every core field's sortOrder from the store's live Customer
     * Address Attributes configuration (or, for `country_id`, from this
     * module's own checkout_index_index.xml layout-XML override, since
     * `getFieldConfig()` prefers a layout-XML sortOrder over the EAV one when
     * both exist).
     *
     * @param int|string $companySortOrder
     * @param int|string $countrySortOrder
     * @return array<string,mixed>
     */
    private function seededLayoutWithCountry($companySortOrder, $countrySortOrder): array
    {
        $seeded = $this->seededLayout();
        $seeded['components']['checkout']['children']['steps']['children']['shipping-step']
            ['children']['shippingAddress']['children']['shipping-address-fieldset']['children'] = [
                'company' => ['label' => 'Company', 'sortOrder' => $companySortOrder],
                'country_id' => ['label' => 'Country', 'sortOrder' => $countrySortOrder],
            ];

        return $seeded;
    }

    /**
     * Country must come before company regardless of what the store's admin
     * configuration (or this module's own static layout-XML number) put in
     * `country_id`'s sortOrder — mirroring PrestaShop's
     * `CustomerAddressFormatter::moveFieldBefore('id_country', 'company')`.
     * Read `company`'s sortOrder back out of the array (already resolved by
     * core by this point) rather than assume a fixed number, so this holds
     * for any store configuration.
     */
    public function testCountrySortsBeforeCompanyRegardlessOfConfiguredSortOrder(): void
    {
        // country_id ALREADY has a lower sortOrder than company: nothing
        // should change.
        $jsLayout = $this->plugin(true)->afterProcess(
            $this->createMock(LayoutProcessor::class),
            $this->seededLayoutWithCountry(60, 50)
        );
        $fieldset = $this->fieldset($jsLayout);
        $this->assertLessThan($fieldset['company']['sortOrder'], $fieldset['country_id']['sortOrder']);

        // country_id's sortOrder is HIGHER than company's — the exact bug
        // reported live (country rendering after street/company). Must be
        // forced below company's.
        $jsLayout = $this->plugin(true)->afterProcess(
            $this->createMock(LayoutProcessor::class),
            $this->seededLayoutWithCountry(60, 90)
        );
        $fieldset = $this->fieldset($jsLayout);
        $this->assertLessThan($fieldset['company']['sortOrder'], $fieldset['country_id']['sortOrder']);
        $this->assertSame(59, $fieldset['country_id']['sortOrder']);
    }

    /**
     * Absent `country_id` (a store somehow without the field at all, or a
     * jsLayout shape this plugin does not recognise) must not blow up or
     * conjure a field that was never there.
     */
    public function testMissingCountryIdIsLeftAlone(): void
    {
        $jsLayout = $this->plugin(true)->afterProcess(
            $this->createMock(LayoutProcessor::class),
            $this->seededLayout()
        );
        $fieldset = $this->fieldset($jsLayout);

        $this->assertArrayNotHasKey('country_id', $fieldset);
    }

    /**
     * `company` present but with no numeric sortOrder yet resolved (a shape
     * this plugin cannot safely reorder against) must leave country_id's
     * sortOrder exactly as core/layout-XML left it, not corrupt it with
     * `null - 1` or similar.
     */
    public function testCompanyWithoutASortOrderLeavesCountryIdUntouched(): void
    {
        $seeded = $this->seededLayout();
        $seeded['components']['checkout']['children']['steps']['children']['shipping-step']
            ['children']['shippingAddress']['children']['shipping-address-fieldset']['children'] = [
                'company' => ['label' => 'Company'],
                'country_id' => ['label' => 'Country', 'sortOrder' => 90],
            ];

        $jsLayout = $this->plugin(true)->afterProcess(
            $this->createMock(LayoutProcessor::class),
            $seeded
        );
        $fieldset = $this->fieldset($jsLayout);

        $this->assertSame(90, $fieldset['country_id']['sortOrder']);
    }
}
