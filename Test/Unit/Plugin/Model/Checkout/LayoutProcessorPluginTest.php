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
}
