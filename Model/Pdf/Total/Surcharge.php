<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace ABN\Gateway\Model\Pdf\Total;

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
        $tax = (float)$source->getDataUsingMethod('two_surcharge_tax_amount');
        if ($amount <= 0) {
            return [];
        }

        $label = $source->getDataUsingMethod('two_surcharge_description')
            ?: (string)__('Two Surcharge');

        $value = $this->getAmountPrefix() . $this->getOrder()->formatPriceTxt($amount + $tax);

        return [[
            'amount'    => $value,
            'label'     => $label . ':',
            'font_size' => $this->getFontSize() ?: 7,
        ]];
    }
}
