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

        $this->moveCountryBeforeCompany($jsLayout);

        return $jsLayout;
    }

    /**
     * Force `country_id` to render before the native `company` AND `street`
     * fields on the SHIPPING address step, only when it does not already.
     *
     * `checkout_index_index.xml` declares `country_id`'s sortOrder as a
     * static 50, which DOES win over Magento core's EAV-derived default (90)
     * — `Magento\Checkout\Block\Checkout\AttributeMerger::getFieldConfig()`
     * prefers a layout-XML sortOrder over the attribute's own when both
     * exist. The problem is what it is being compared against: `company`
     * and `street` carry no layout-XML override of their own from this
     * module, so THEIR position is whatever the store's Customer Address
     * Attributes admin screen has configured for those two attributes —
     * out of the box that is 60/70 (after our 50), but any store free to
     * reconfigure EITHER below 50 reproduces "country after an address
     * field", and this staging store's live behaviour (country after
     * street specifically) is exactly that. A static number picked once
     * cannot track an admin setting this module does not own. Anchoring to
     * `company` alone is not enough — `street` is independently
     * configurable and was the actual field reported live, so this anchors
     * to whichever of the two currently sorts first.
     *
     * Read both sortOrders back out of the array this plugin's own
     * `afterProcess` runs AFTER — i.e. once the core merge has already
     * resolved them against whatever the store's real config says — and
     * place country immediately before the earlier of the two. Mirrors
     * PrestaShop's `CustomerAddressFormatter::moveFieldBefore('id_country',
     * 'company')`: dynamic relative positioning rather than a static
     * number, so it holds regardless of admin configuration.
     *
     * Deliberately CONDITIONAL, not an unconditional overwrite: a store
     * where country already sorts correctly is left untouched, so this
     * cannot push country past some OTHER field (region, city, postcode, a
     * custom EAV attribute) that happened to land between country's
     * original position and `min(company, street) - 1`. Only a store that
     * actually reproduces the reported bug gets reordered.
     *
     * Scoped to the SHIPPING address step only. The payment step's billing
     * address form (when "same as shipping" is unchecked) is a separate
     * jsLayout subtree this method does not touch — out of scope for this
     * fix; the same live-config-dependent ordering issue can in principle
     * reproduce there too and would need its own follow-up if reported.
     *
     * @param array $jsLayout
     * @return void
     */
    private function moveCountryBeforeCompany(array &$jsLayout)
    {
        if (!isset(
            $jsLayout['components']['checkout']['children']['steps']['children']['shipping-step']
            ['children']['shippingAddress']['children']['shipping-address-fieldset']['children']
        )) {
            return;
        }
        $fieldset = &$jsLayout['components']['checkout']['children']['steps']['children']['shipping-step']
            ['children']['shippingAddress']['children']['shipping-address-fieldset']['children'];

        if (!isset($fieldset['country_id']) || !is_array($fieldset['country_id'])
            || !isset($fieldset['country_id']['sortOrder'])
            || !is_numeric($fieldset['country_id']['sortOrder'])
        ) {
            return;
        }

        $anchors = [];
        foreach (['company', 'street'] as $fieldName) {
            if (isset($fieldset[$fieldName]['sortOrder']) && is_numeric($fieldset[$fieldName]['sortOrder'])) {
                $anchors[] = $fieldset[$fieldName]['sortOrder'];
            }
        }
        if (empty($anchors)) {
            return;
        }
        $target = min($anchors);

        if ($fieldset['country_id']['sortOrder'] < $target) {
            // Already correctly ordered — leave it exactly as core/XML set
            // it, so this never shoves country past some third field that
            // happens to sit between its current position and the target.
            return;
        }
        $fieldset['country_id']['sortOrder'] = $target - 1;
    }
}
