<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Model\Pdf\Total;

use Magento\Sales\Model\Order\Pdf\Total\DefaultTotal;
use Magento\Tax\Helper\Data as TaxHelper;
use Magento\Tax\Model\Calculation;
use Magento\Tax\Model\ResourceModel\Sales\Order\Tax\CollectionFactory;
use Two\Gateway\Service\Order\SurchargeDisplay;

/**
 * PDF totals renderer for the Two surcharge.
 *
 * Registered for invoice + creditmemo PDFs via etc/pdf.xml. Returns an empty
 * array when the source has no surcharge so the line is skipped instead of
 * showing a 0.00 row.
 *
 * Net or gross follows `tax/sales_display/price`, and "Both" emits the paired
 * excl/incl lines core uses for Subtotal and Shipping.
 */
class Surcharge extends DefaultTotal
{
    private SurchargeDisplay $surchargeDisplay;

    public function __construct(
        TaxHelper $taxHelper,
        Calculation $taxCalculation,
        CollectionFactory $ordersFactory,
        SurchargeDisplay $surchargeDisplay,
        array $data = []
    ) {
        parent::__construct($taxHelper, $taxCalculation, $ordersFactory, $data);
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
     * @inheritDoc
     */
    public function getTotalsForDisplay()
    {
        $source = $this->getSource();
        $amount = (float)$source->getDataUsingMethod('two_surcharge_amount');
        if ($amount <= 0) {
            return [];
        }

        $tax = (float)$source->getDataUsingMethod('two_surcharge_tax_amount');
        $order = $this->getOrder();
        $label = $source->getDataUsingMethod('two_surcharge_description')
            ?: (string)__('Surcharge');
        $fontSize = $this->getFontSize() ?: 7;
        $display = $this->getSurchargeDisplay();
        $mode = $display->forSales($order->getStore());

        if ($mode === SurchargeDisplay::BOTH) {
            return [
                [
                    'amount'    => $this->getAmountPrefix() . $order->formatPriceTxt($amount),
                    'label'     => (string)__('%1 (Excl. Tax)', $label) . ':',
                    'font_size' => $fontSize,
                ],
                [
                    'amount'    => $this->getAmountPrefix() . $order->formatPriceTxt($amount + $tax),
                    'label'     => (string)__('%1 (Incl. Tax)', $label) . ':',
                    'font_size' => $fontSize,
                ],
            ];
        }

        $value = $display->pick($mode, $amount, $tax);

        return [[
            'amount'    => $this->getAmountPrefix() . $order->formatPriceTxt($value),
            'label'     => $label . ':',
            'font_size' => $fontSize,
        ]];
    }
}
