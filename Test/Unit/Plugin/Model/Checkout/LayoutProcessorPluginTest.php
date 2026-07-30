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
     * @return array<string,mixed>
     */
    private function companyIdField(): array
    {
        $plugin = new LayoutProcessorPlugin($this->createMock(Repository::class));
        $jsLayout = $plugin->afterProcess($this->createMock(LayoutProcessor::class), []);

        return $jsLayout['components']['checkout']['children']['steps']['children']
            ['shipping-step']['children']['shippingAddress']['children']
            ['shipping-address-fieldset']['children']['company_id'];
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
        // string in every shipped i18n CSV.
        $this->assertSame('Company Number', $field['label']);
        $this->assertSame('Company Number', $field['config']['tooltip']['description']);
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
