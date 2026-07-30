<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Two\Gateway\Plugin\Model\Checkout;

use Magento\Checkout\Block\Checkout\LayoutProcessor;
use Two\Gateway\Model\Config\Repository;

class LayoutProcessorPlugin
{
    /**
     * @var Repository
     */
    private $repository;

    /**
     * @param Repository $repository
     */
    public function __construct(
        Repository $repository
    ) {
        $this->repository = $repository;
    }

    /**
     * @param LayoutProcessor $subject
     * @param array $jsLayout
     * @return array
     */
    public function afterProcess(
        LayoutProcessor $subject,
        array  $jsLayout
    ) {
        // The field is `visible` now, so a merchant who merely has the module
        // installed with the payment method switched off would otherwise get a
        // company-number input on the address step for every buyer. Gated the
        // same way the payment-method list plugin gates its own append.
        if (!$this->repository->isActive()) {
            return $jsLayout;
        }
        $jsLayout['components']['checkout']['children']['steps']['children']['shipping-step']['children']
        ['shippingAddress']['children']['shipping-address-fieldset']['children']['company_id'] = [
            'component' => 'Magento_Ui/js/form/element/abstract',
            'config' => [
                'customScope' => 'shippingAddress.custom_attributes',
                'customEntry' => null,
                'template' => 'ui/form/field',
                'elementTmpl' => 'ui/form/element/input',
                'tooltip' => [
                    'description' => __('Company Number'),
                ],
                'options' => [],
                'id' => 'company-id'
            ],
            'dataScope' => 'shippingAddress.custom_attributes.company_id',
            'label' => __('Company Number'),
            'provider' => 'checkoutProvider',
            // Rendered, and disabled until the buyer actually has to supply
            // the number by hand. Company search fills it from the registry
            // for a picked company, so an editable field would only let the
            // buyer contradict the registry; the exception is a company the
            // registry holds no identifier for, where typing it is the only
            // route. The live derivation of that state — and the publishing of
            // whatever the buyer types — belongs to
            // view/frontend/web/js/view/address-autocomplete.js; `disabled`
            // here is only the initial state for a freshly rendered form with
            // no company selected yet.
            //
            // `visible` stays true on purpose. Flipping it to false would pull
            // the component out of the UI-registry render tree entirely, and
            // view/frontend/web/js/view/address-autocomplete.js calls
            // `uiRegistry.get(this.companyIdComponent)` on this field, so a
            // registry-absent component would break that lookup, not just hide
            // the input. The field is hidden visually instead, via
            // `additionalClasses` + a CSS rule in
            // view/frontend/web/css/style.css — the DOM node stays present and
            // its value still submits as
            // shippingAddress.custom_attributes.company_id. See TWO-25288.
            'visible' => true,
            'additionalClasses' => 'two-company-id-hidden',
            'disabled' => true,
            'validation' => [
                'required-entry' => false
            ],
            'sortOrder' => 65,
            'options' => [],
            'filterBy' => null,
            'customEntry' => null,
            'id' => 'company-id',
            'value' => ''
        ];
        return $jsLayout;
    }
}
