<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Block\Sales\Total;

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
        if ($amount <= 0) {
            return $this;
        }

        // Display the NET surcharge — its VAT lives in the Tax line, exactly as
        // on checkout. The net rows (items + shipping + tax + net surcharge)
        // then reconcile to the grand total. (Showing gross here double-
        // presents the surcharge VAT — once in the Tax line, once in this row.)
        $value = $amount;
        $baseValue = (float)$source->getDataUsingMethod('base_two_surcharge_amount');

        $label = $source->getDataUsingMethod('two_surcharge_description');
        if (!$label) {
            $label = (string)__('Two Surcharge');
        }

        // Place the surcharge row directly above the Tax line — the surcharge
        // is part of the tax base, so net surcharge then tax reads naturally
        // (and matches checkout ordering).
        $parent->addTotalBefore(
            new DataObject([
                'code'       => 'two_surcharge',
                'value'      => $value,
                'base_value' => $baseValue,
                'label'      => $label,
            ]),
            'tax'
        );

        return $this;
    }
}
