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
     * A `jsLayout` shaped like seededLayout(), but with `country_id`,
     * `company` and (optionally) `street` all already present carrying some
     * sortOrder — i.e. the shape the fieldset is ACTUALLY in by the time
     * this plugin's afterProcess runs, once Magento_Checkout's own
     * `AttributeMerger::getFieldConfig()` has already resolved every core
     * field's sortOrder from the store's live Customer Address Attributes
     * configuration (or, for `country_id`, from this module's own
     * checkout_index_index.xml layout-XML override, since `getFieldConfig()`
     * prefers a layout-XML sortOrder over the EAV one when both exist).
     *
     * @param mixed $companySortOrder
     * @param mixed $countrySortOrder
     * @param mixed $streetSortOrder pass null to omit the field entirely
     * @return array<string,mixed>
     */
    private function seededLayoutWithCountry($companySortOrder, $countrySortOrder, $streetSortOrder = 999): array
    {
        $seeded = $this->seededLayout();
        $children = [
            'company' => ['label' => 'Company', 'sortOrder' => $companySortOrder],
            'country_id' => ['label' => 'Country', 'sortOrder' => $countrySortOrder],
        ];
        if ($streetSortOrder !== null) {
            $children['street'] = ['label' => 'Street Address', 'sortOrder' => $streetSortOrder];
        }
        $seeded['components']['checkout']['children']['steps']['children']['shipping-step']
            ['children']['shippingAddress']['children']['shipping-address-fieldset']['children'] = $children;

        return $seeded;
    }

    /**
     * Country must come before company AND street regardless of what the
     * store's admin configuration (or this module's own static layout-XML
     * number) put in `country_id`'s sortOrder — mirroring PrestaShop's
     * `CustomerAddressFormatter::moveFieldBefore('id_country', 'company')`.
     * Read both sortOrders back out of the array (already resolved by core
     * by this point) rather than assume a fixed number, so this holds for
     * any store configuration. Anchoring to company ALONE was the exact gap
     * an earlier round of this fix shipped with — street is independently
     * configurable and was the field actually reported live.
     */
    public function testCountrySortsBeforeCompanyAndStreetRegardlessOfConfiguredSortOrder(): void
    {
        // Both already sort after country: nothing should change, and this
        // is asserted as a NO-OP (exact value), not just "still less than" —
        // an earlier draft of this method rewrote sortOrder unconditionally
        // even when nothing was wrong, which this pins against.
        $jsLayout = $this->plugin(true)->afterProcess(
            $this->createMock(LayoutProcessor::class),
            $this->seededLayoutWithCountry(60, 50, 70)
        );
        $fieldset = $this->fieldset($jsLayout);
        $this->assertSame(50, $fieldset['country_id']['sortOrder']);

        // country_id's sortOrder is HIGHER than company's (street is out of
        // the way) — the fix must anchor on company.
        $jsLayout = $this->plugin(true)->afterProcess(
            $this->createMock(LayoutProcessor::class),
            $this->seededLayoutWithCountry(60, 90, 999)
        );
        $fieldset = $this->fieldset($jsLayout);
        $this->assertSame(59, $fieldset['country_id']['sortOrder']);

        // street reconfigured BELOW company — the exact bug reported live
        // (country after STREET specifically). Must anchor on street, the
        // lower of the two, not on company.
        $jsLayout = $this->plugin(true)->afterProcess(
            $this->createMock(LayoutProcessor::class),
            $this->seededLayoutWithCountry(60, 90, 30)
        );
        $fieldset = $this->fieldset($jsLayout);
        $this->assertSame(29, $fieldset['country_id']['sortOrder']);
        $this->assertLessThan($fieldset['company']['sortOrder'], $fieldset['country_id']['sortOrder']);
        $this->assertLessThan($fieldset['street']['sortOrder'], $fieldset['country_id']['sortOrder']);
    }

    /**
     * `street` absent (a jsLayout shape without it, or a store that somehow
     * has no street attribute) must still anchor correctly on `company`
     * alone, not blow up on the missing key.
     */
    public function testMissingStreetStillAnchorsOnCompany(): void
    {
        $jsLayout = $this->plugin(true)->afterProcess(
            $this->createMock(LayoutProcessor::class),
            $this->seededLayoutWithCountry(60, 90, null)
        );
        $fieldset = $this->fieldset($jsLayout);

        $this->assertSame(59, $fieldset['country_id']['sortOrder']);
    }

    /**
     * The symmetric case to testMissingStreetStillAnchorsOnCompany(): the
     * anchor loop is `foreach (['company', 'street'] as $fieldName)`, so
     * `company` missing entirely (a store somehow without that attribute, or
     * a jsLayout shape without it) must still anchor correctly on `street`
     * alone.
     */
    public function testMissingCompanyStillAnchorsOnStreet(): void
    {
        $seeded = $this->seededLayout();
        $seeded['components']['checkout']['children']['steps']['children']['shipping-step']
            ['children']['shippingAddress']['children']['shipping-address-fieldset']['children'] = [
                'street' => ['label' => 'Street Address', 'sortOrder' => 70],
                'country_id' => ['label' => 'Country', 'sortOrder' => 90],
            ];

        $jsLayout = $this->plugin(true)->afterProcess(
            $this->createMock(LayoutProcessor::class),
            $seeded
        );
        $fieldset = $this->fieldset($jsLayout);

        $this->assertSame(69, $fieldset['country_id']['sortOrder']);
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
     * `country_id` present as a non-array scalar (a foreign/malformed
     * component contributed by some other module) must not crash trying to
     * write an array offset onto it.
     */
    public function testScalarCountryIdDoesNotCrash(): void
    {
        $seeded = $this->seededLayout();
        $seeded['components']['checkout']['children']['steps']['children']['shipping-step']
            ['children']['shippingAddress']['children']['shipping-address-fieldset']['children'] = [
                'company' => ['label' => 'Company', 'sortOrder' => 60],
                'country_id' => 'not-an-array',
            ];

        $jsLayout = $this->plugin(true)->afterProcess(
            $this->createMock(LayoutProcessor::class),
            $seeded
        );
        $fieldset = $this->fieldset($jsLayout);

        $this->assertSame('not-an-array', $fieldset['country_id']);
    }

    /**
     * `company` (and `street`, if present) with no numeric sortOrder yet
     * resolved — a shape this plugin cannot safely reorder against — must
     * leave country_id's sortOrder exactly as core/layout-XML left it, not
     * corrupt it with `null - 1` or similar.
     */
    public function testFieldsWithoutASortOrderLeaveCountryIdUntouched(): void
    {
        $seeded = $this->seededLayout();
        $seeded['components']['checkout']['children']['steps']['children']['shipping-step']
            ['children']['shippingAddress']['children']['shipping-address-fieldset']['children'] = [
                'company' => ['label' => 'Company'],
                'street' => ['label' => 'Street Address'],
                'country_id' => ['label' => 'Country', 'sortOrder' => 90],
            ];

        $jsLayout = $this->plugin(true)->afterProcess(
            $this->createMock(LayoutProcessor::class),
            $seeded
        );
        $fieldset = $this->fieldset($jsLayout);

        $this->assertSame(90, $fieldset['country_id']['sortOrder']);
    }

    /** Core's own component, the only thing that identifies a billing form. */
    private const BILLING_COMPONENT = 'Magento_Checkout/js/view/billing-address';

    /**
     * One billing address form as core generates it.
     *
     * @param string $scopePrefix
     * @param array<string,mixed> $fields the form's own address fields
     * @return array<string,mixed>
     */
    private function billingForm(string $scopePrefix, array $fields = []): array
    {
        return [
            'component' => self::BILLING_COMPONENT,
            'dataScopePrefix' => $scopePrefix,
            'children' => [
                'form-fields' => [
                    'children' => $fields ?: ['company' => ['label' => 'Company']],
                ],
            ],
        ];
    }

    /**
     * A `jsLayout` carrying whichever of the two billing containers the store's
     * *Display Billing Address On* setting produces.
     *
     * @param array<string,mixed> $paymentsList `payments-list` children
     * @param array<string,mixed> $afterMethods `afterMethods` children
     * @return array<string,mixed>
     */
    private function seededLayoutWithBilling(array $paymentsList = [], array $afterMethods = []): array
    {
        $seeded = $this->seededLayout();
        $payment = [];
        if ($paymentsList !== []) {
            $payment['payments-list'] = ['children' => $paymentsList];
        }
        if ($afterMethods !== []) {
            $payment['afterMethods'] = ['children' => $afterMethods];
        }
        $seeded['components']['checkout']['children']['steps']['children']['billing-step'] = [
            'children' => ['payment' => ['children' => $payment]],
        ];

        return $seeded;
    }

    /**
     * One billing form's fields after the plugin has run.
     *
     * @param array<string,mixed> $jsLayout
     * @param string $container
     * @param string $formName
     * @return array<string,mixed>
     */
    private function billingFields(array $jsLayout, string $container, string $formName): array
    {
        return $jsLayout['components']['checkout']['children']['steps']['children']['billing-step']
            ['children']['payment']['children'][$container]['children'][$formName]
            ['children']['form-fields']['children'];
    }

    /**
     * @param array<string,mixed> $jsLayout
     * @return array<string,mixed>
     */
    private function process(array $jsLayout, bool $isActive = true): array
    {
        return $this->plugin($isActive)->afterProcess(
            $this->createMock(LayoutProcessor::class),
            $jsLayout
        );
    }

    /**
     * Both containers are walked because which one exists is an admin setting
     * (*Display Billing Address On*), and a form is matched by its component
     * rather than by a path or a method code.
     *
     * @return array<int,array{0: array<string,mixed>, 1: array<string,mixed>, 2: array<string,string>, 3: string}>
     */
    public static function billingContainerProvider(): array
    {
        return [
            [['two_gateway-form' => null], [], ['payments-list' => 'two_gateway-form'], 'a per-method billing form'],
            [[], ['billing-address-form' => null], ['afterMethods' => 'billing-address-form'], 'the shared billing form'],
            [['two_gateway-form' => null], ['billing-address-form' => null], ['payments-list' => 'two_gateway-form', 'afterMethods' => 'billing-address-form'], 'both containers at once'],
            [[], [], [], 'neither container present'],
        ];
    }

    /**
     * @dataProvider billingContainerProvider
     * @param array<string,mixed> $paymentsList
     * @param array<string,mixed> $afterMethods
     * @param array<string,string> $expected container => form name
     */
    public function testCompanyNumberFieldIsInjectedIntoEveryBillingForm(
        array $paymentsList,
        array $afterMethods,
        array $expected,
        string $description
    ): void {
        foreach (array_keys($paymentsList) as $name) {
            $paymentsList[$name] = $this->billingForm('billingAddresstwo_gateway');
        }
        foreach (array_keys($afterMethods) as $name) {
            $afterMethods[$name] = $this->billingForm('billingAddressshared');
        }

        $jsLayout = $this->process($this->seededLayoutWithBilling($paymentsList, $afterMethods));

        foreach ($expected as $container => $formName) {
            $fields = $this->billingFields($jsLayout, $container, $formName);
            $this->assertArrayHasKey('company_id', $fields, $description);
            // The seeded sibling surviving alongside is what pins the write to
            // the real form rather than a path the plugin conjured.
            $this->assertSame(['company', 'company_id'], array_keys($fields), $description);
        }
        // Nowhere else in the payment subtree — with neither container present
        // that is the only thing this row has to say.
        $this->assertCount(
            count($expected),
            $this->injectedFields($this->paymentChildren($jsLayout)),
            $description
        );
        // The shipping injection is unaffected by the billing walk.
        $this->assertArrayHasKey('company_id', $this->fieldset($jsLayout), $description);
    }

    /**
     * @param array<string,mixed> $jsLayout
     * @return array<string,mixed> the payment step's own `children`
     */
    private function paymentChildren(array $jsLayout): array
    {
        return $jsLayout['components']['checkout']['children']['steps']['children']['billing-step']
            ['children']['payment']['children'];
    }

    /**
     * @param array<string,mixed> $subtree
     * @return array<int,mixed> every `company_id` this plugin injected below it
     */
    private function injectedFields(array $subtree): array
    {
        $found = [];
        foreach ($subtree as $key => $value) {
            if (!is_array($value)) {
                continue;
            }
            if ($key === 'company_id') {
                $found[] = $value;
                continue;
            }
            $found = array_merge($found, $this->injectedFields($value));
        }

        return $found;
    }

    /**
     * A checkout that renders billing with its own component, or wraps core's
     * in a container of its own, still binds the form to core's
     * `billingAddress` scope — and the number has to submit with that address.
     *
     * @return array<int,array{0: array<string,mixed>, 1: string, 2: string}>
     */
    public static function thirdPartyBillingProvider(): array
    {
        $ownComponent = [
            'component' => 'Vendor_Checkout/js/view/billing-address',
            'dataScopePrefix' => 'billingAddressvendor_method',
            'children' => ['form-fields' => ['children' => ['company' => ['label' => 'Company']]]],
        ];

        return [
            [$ownComponent, 'billingAddressvendor_method', 'a third-party billing component'],
            [
                ['component' => 'Vendor_Checkout/js/view/wrapper', 'children' => ['inner' => $ownComponent]],
                'billingAddressvendor_method',
                'core\'s form wrapped in a third-party container',
            ],
        ];
    }

    /**
     * @dataProvider thirdPartyBillingProvider
     * @param array<string,mixed> $node
     */
    public function testThirdPartyBillingFormsAreFilledToo(
        array $node,
        string $scopePrefix,
        string $description
    ): void {
        $jsLayout = $this->process($this->seededLayoutWithBilling(['billing-node' => $node]));

        $injected = $this->injectedFields($this->paymentChildren($jsLayout));

        $this->assertCount(1, $injected, $description);
        $this->assertSame(
            $scopePrefix . '.custom_attributes.company_id',
            $injected[0]['dataScope'],
            $description
        );
    }

    /**
     * @return array<int,array{0: string, 1: string}>
     */
    public static function billingScopeProvider(): array
    {
        return [
            ['billingAddresstwo_gateway', 'a per-method form scope'],
            ['billingAddressshared', 'the shared form scope'],
        ];
    }

    /**
     * @dataProvider billingScopeProvider
     */
    public function testBillingScopesAreDerivedFromTheFormsOwnDataScopePrefix(
        string $scopePrefix,
        string $description
    ): void {
        $jsLayout = $this->process(
            $this->seededLayoutWithBilling(['two_gateway-form' => $this->billingForm($scopePrefix)])
        );
        $field = $this->billingFields($jsLayout, 'payments-list', 'two_gateway-form')['company_id'];

        $this->assertSame($scopePrefix . '.custom_attributes.company_id', $field['dataScope'], $description);
        $this->assertSame($scopePrefix . '.custom_attributes', $field['config']['customScope'], $description);
    }

    /**
     * Country decides which national registry the company search queries, so it
     * has to sort before the company field on a billing form too — and unlike
     * the shipping fieldset, a billing one carries no layout-XML sortOrder from
     * this module at all, so the stock EAV order reproduces the mis-ordering on
     * every store.
     *
     * @return array<int,array{0: array<string,mixed>, 1: mixed, 2: string}>
     */
    public static function billingReorderProvider(): array
    {
        return [
            [['company' => 60, 'street' => 70, 'country_id' => 90], 59, 'stock EAV order — country after company'],
            [['company' => 20, 'street' => 70, 'country_id' => 50], 19, 'country after company only'],
            [['company' => 90, 'street' => 30, 'country_id' => 50], 29, 'country after street only'],
            [['company' => 60, 'street' => 70, 'country_id' => 50], 50, 'country already first — untouched'],
            [['company' => null, 'street' => null, 'country_id' => 90], 90, 'no numeric anchor — untouched'],
            [['company' => 60, 'street' => 70, 'country_id' => null], null, 'no numeric country sortOrder — untouched'],
        ];
    }

    /**
     * @dataProvider billingReorderProvider
     * @param array<string,mixed> $sortOrders
     * @param mixed $expected
     */
    public function testBillingCountrySortsBeforeCompanyAndStreet(
        array $sortOrders,
        $expected,
        string $description
    ): void {
        $fields = [];
        foreach ($sortOrders as $name => $sortOrder) {
            $fields[$name] = $sortOrder === null ? ['label' => $name] : ['label' => $name, 'sortOrder' => $sortOrder];
        }

        $jsLayout = $this->process(
            $this->seededLayoutWithBilling(
                ['two_gateway-form' => $this->billingForm('billingAddresstwo_gateway', $fields)]
            )
        );
        $country = $this->billingFields($jsLayout, 'payments-list', 'two_gateway-form')['country_id'];

        $this->assertSame($expected, $country['sortOrder'] ?? null, $description);
    }

    /**
     * @return array<int,array{0: array<string,mixed>, 1: string}>
     */
    public static function untouchedBillingNodeProvider(): array
    {
        return [
            [['component' => 'Magento_Checkout/js/view/payment/list', 'children' => ['form-fields' => ['children' => []]]], 'a non-billing component'],
            [['component' => self::BILLING_COMPONENT, 'children' => ['form-fields' => ['children' => []]]], 'a billing form with no dataScopePrefix'],
            [['component' => self::BILLING_COMPONENT, 'dataScopePrefix' => ''], 'an empty dataScopePrefix'],
            [['component' => self::BILLING_COMPONENT, 'dataScopePrefix' => 'billingAddressshared'], 'a billing form with no form-fields'],
            [
                [
                    'component' => 'Vendor_Module/js/view/some-fieldset',
                    'dataScopePrefix' => 'shippingAddress',
                    'children' => ['form-fields' => ['children' => ['city' => ['label' => 'City']]]],
                ],
                'a fieldset whose scope is not a billing one, complete in every other respect',
            ],
            [
                [
                    'component' => self::BILLING_COMPONENT,
                    'dataScopePrefix' => '',
                    'children' => ['form-fields' => ['children' => ['city' => ['label' => 'City']]]],
                ],
                'core\'s own component with no scope to bind a number to',
            ],
            ['not-an-array', 'a scalar child'],
        ];
    }

    /**
     * @dataProvider untouchedBillingNodeProvider
     * @param mixed $node
     */
    public function testChildrenThatAreNotUsableBillingFormsAreLeftAlone($node, string $description): void
    {
        $seeded = $this->seededLayoutWithBilling(['some-child' => $node]);

        $jsLayout = $this->process($seeded);
        $processed = $jsLayout['components']['checkout']['children']['steps']['children']['billing-step']
            ['children']['payment']['children']['payments-list']['children']['some-child'];

        $this->assertSame($node, $processed, $description);
    }

    public function testNothingIsInjectedIntoBillingWhenThePaymentMethodIsInactive(): void
    {
        $seeded = $this->seededLayoutWithBilling(
            ['two_gateway-form' => $this->billingForm('billingAddresstwo_gateway', [
                'company' => ['label' => 'Company', 'sortOrder' => 60],
                'country_id' => ['label' => 'Country', 'sortOrder' => 90],
            ])]
        );

        $this->assertSame($seeded, $this->process($seeded, false));
    }
}
