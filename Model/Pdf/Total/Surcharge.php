<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Model\Pdf\Total;

use Magento\Sales\Model\Order\Pdf\Total\DefaultTotal;

/**
 * PDF totals renderer for the Two surcharge.
 *
 * Registered for invoice + creditmemo PDFs via etc/di.xml. Returns an empty
 * array when the source has no surcharge so the line is skipped instead of
 * showing a 0.00 row.
 */
class Surcharge extends DefaultTotal
{
    /**
     * @inheritDoc
     */
    public function getTotalsForDisplay()
    {
        $source = $this->getSource();
        $amount = (float)$source->getDataUsingMethod('two_surcharge_amount');
        if ($amount <= 0) {
            return [];
        }

        $label = $source->getDataUsingMethod('two_surcharge_description')
            ?: (string)__('Two Surcharge');

        // NET surcharge — its VAT is shown in the Tax line, matching the
        // on-screen totals, the checkout, and the grand total.
        $value = $this->getAmountPrefix() . $this->getOrder()->formatPriceTxt($amount);

        return [[
            'amount'    => $value,
            'label'     => $label . ':',
            'font_size' => $this->getFontSize() ?: 7,
        ]];
    }
}
