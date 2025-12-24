<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */

namespace ABN\Gateway\Plugin\Model\Checkout;

use Magento\Checkout\Block\Checkout\LayoutProcessor;
use ABN\Gateway\Model\Config\Repository;

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
        $jsLayout['components']['checkout']['children']['steps']['children']['shipping-step']['children']
        ['shippingAddress']['children']['shipping-address-fieldset']['children']['company_id'] = [
            'component' => 'Magento_Ui/js/form/element/abstract',
            'config' => [
                'customScope' => 'shippingAddress.custom_attributes',
                'customEntry' => null,
                'template' => 'ui/form/field',
                'elementTmpl' => 'ui/form/element/input',
                'tooltip' => [
                    'description' => 'Company Id',
                ],
                'options' => [],
                'id' => 'company-id'
            ],
            'dataScope' => 'shippingAddress.custom_attributes.company_id',
            'label' => 'Company Id',
            'provider' => 'checkoutProvider',
            'visible' => false,
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
