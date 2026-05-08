<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace ABN\Gateway\Block\Sales\Total;

use Magento\Framework\DataObject;
use Magento\Framework\View\Element\Template;

/**
 * Renders the Two surcharge row in order/invoice/creditmemo totals.
 *
 * Layout XML inserts this as a child of the order_totals / invoice_totals /
 * creditmemo_totals block. initTotals() reads the surcharge from the parent
 * block's source (the order/invoice/creditmemo) and registers a totals row.
 */
class Surcharge extends Template
{
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

        $amount = (float)$source->getDataUsingMethod('two_surcharge_amount');
        $taxAmount = (float)$source->getDataUsingMethod('two_surcharge_tax_amount');
        if ($amount <= 0) {
            return $this;
        }

        // Display gross (net + tax) so the row matches the invoice/order grand
        // total math; tax is otherwise hidden in the Tax line.
        $value = $amount + $taxAmount;
        $baseAmount = (float)$source->getDataUsingMethod('base_two_surcharge_amount');
        $baseTax = (float)$source->getDataUsingMethod('base_two_surcharge_tax_amount');
        $baseValue = $baseAmount + $baseTax;

        $label = $source->getDataUsingMethod('two_surcharge_description');
        if (!$label) {
            $label = (string)__('Two Surcharge');
        }

        $parent->addTotalBefore(
            new DataObject([
                'code'       => 'two_surcharge',
                'value'      => $value,
                'base_value' => $baseValue,
                'label'      => $label,
            ]),
            'grand_total'
        );

        return $this;
    }
}
