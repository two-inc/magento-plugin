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
 * PDF totals renderer for a reconciled unitemized charge. Without it the
 * credit-memo PDF a merchant sends out prints rows that do not sum to its own
 * grand total.
 *
 * Net or gross follows `tax/sales_display/price`, and "Both" emits the paired
 * excl/incl lines core uses for Subtotal and Shipping.
 */
class OtherCharges extends DefaultTotal
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
        $amount = (float)$source->getDataUsingMethod('two_other_charges_amount');
        if ($amount <= 0) {
            return [];
        }

        $tax = (float)$source->getDataUsingMethod('two_other_charges_tax_amount');
        $order = $this->getOrder();
        $label = (string)__('Other charges');
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
