<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Block\Sales\Total;

use Magento\Framework\DataObject;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Two\Gateway\Service\Order\SurchargeDisplay;

/**
 * Renders the "Other charges" row in credit-memo totals, for whatever
 * Model\Total\Creditmemo\OtherCharges reconciled. The label names no source
 * because the collector never learns one.
 *
 * Net or gross follows `tax/sales_display/price`, and "Both" emits the paired
 * excl/incl rows core uses for Subtotal and Shipping.
 */
class OtherCharges extends Template
{
    private SurchargeDisplay $surchargeDisplay;

    public function __construct(
        Context $context,
        SurchargeDisplay $surchargeDisplay,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->surchargeDisplay = $surchargeDisplay;
    }

    /**
     * Wrapped behind a method so unit tests built via an anonymous subclass
     * with a no-op constructor can override just this accessor.
     */
    protected function getSurchargeDisplay(): SurchargeDisplay
    {
        return $this->surchargeDisplay;
    }

    /**
     * @return $this
     */
    public function initTotals(): self
    {
        $parent = $this->getParentBlock();
        if (!$parent) {
            return $this;
        }

        $source = $parent->getSource();
        if (!$source) {
            return $this;
        }

        $amount = (float)$source->getDataUsingMethod('two_other_charges_amount');
        if ($amount <= 0) {
            return $this;
        }

        $baseAmount = (float)$source->getDataUsingMethod('base_two_other_charges_amount');
        $tax = (float)$source->getDataUsingMethod('two_other_charges_tax_amount');
        $baseTax = (float)$source->getDataUsingMethod('base_two_other_charges_tax_amount');

        $label = (string)__('Other charges');

        $display = $this->getSurchargeDisplay();
        $mode = $display->forSales($this->resolveStore($source));

        // Above the Tax line: the charge is part of the tax base.
        if ($mode === SurchargeDisplay::BOTH) {
            $parent->addTotalBefore(
                new DataObject([
                    'code'       => 'two_other_charges_excl',
                    'value'      => $amount,
                    'base_value' => $baseAmount,
                    'label'      => __('%1 (Excl. Tax)', $label),
                ]),
                'tax'
            );
            $parent->addTotal(
                new DataObject([
                    'code'       => 'two_other_charges_incl',
                    'value'      => $amount + $tax,
                    'base_value' => $baseAmount + $baseTax,
                    'label'      => __('%1 (Incl. Tax)', $label),
                ]),
                'two_other_charges_excl'
            );

            return $this;
        }

        $parent->addTotalBefore(
            new DataObject([
                'code'       => 'two_other_charges',
                'value'      => $display->pick($mode, $amount, $tax),
                'base_value' => $display->pick($mode, $baseAmount, $baseTax),
                'label'      => $label,
            ]),
            'tax'
        );

        return $this;
    }

    /**
     * The document's own store, not the current one — an admin view renders
     * outside the store whose tax settings apply.
     *
     * @param mixed $source creditmemo
     * @return \Magento\Store\Model\Store|null
     */
    private function resolveStore($source)
    {
        return method_exists($source, 'getStore') ? $source->getStore() : null;
    }
}
