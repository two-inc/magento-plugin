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
use Two\Gateway\Api\BrandRegistryInterface;
use Two\Gateway\Service\Order\SurchargeDisplay;

/**
 * Renders the [Brand] surcharge row in order/invoice/creditmemo totals.
 *
 * Layout XML inserts this as a child of the order_totals / invoice_totals /
 * creditmemo_totals block. initTotals() reads the surcharge from the parent
 * block's source (the order/invoice/creditmemo) and registers a totals row.
 *
 * Net or gross follows `tax/sales_display/price`, and "Both" emits the paired
 * excl/incl rows core uses for Subtotal and Shipping.
 */
class Surcharge extends Template
{
    private BrandRegistryInterface $brandRegistry;

    private SurchargeDisplay $surchargeDisplay;

    public function __construct(
        Context $context,
        BrandRegistryInterface $brandRegistry,
        SurchargeDisplay $surchargeDisplay,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->brandRegistry = $brandRegistry;
        $this->surchargeDisplay = $surchargeDisplay;
    }

    /**
     * Wrapped behind a method so unit tests built via an anonymous subclass
     * with a no-op constructor (the existing pattern in SurchargeTest, which
     * never sets $brandRegistry) can override just this accessor.
     */
    protected function getBrandRegistry(): BrandRegistryInterface
    {
        return $this->brandRegistry;
    }

    /**
     * Wrapped for the same reason as getBrandRegistry().
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

        $amount = (float)$source->getDataUsingMethod('two_surcharge_amount');
        if ($amount <= 0) {
            return $this;
        }

        $baseAmount = (float)$source->getDataUsingMethod('base_two_surcharge_amount');
        $tax = (float)$source->getDataUsingMethod('two_surcharge_tax_amount');
        $baseTax = (float)$source->getDataUsingMethod('base_two_surcharge_tax_amount');

        $label = $source->getDataUsingMethod('two_surcharge_description');
        if (!$label) {
            $label = (string)__('%1 surcharge', $this->getBrandRegistry()->getProductName());
        }

        $display = $this->getSurchargeDisplay();
        $mode = $display->forSales($this->resolveStore($source));

        // Place the surcharge row(s) directly above the Tax line — the
        // surcharge is part of the tax base, so surcharge then tax reads
        // naturally (and matches checkout ordering).
        if ($mode === SurchargeDisplay::BOTH) {
            $parent->addTotalBefore(
                new DataObject([
                    'code'       => 'two_surcharge_excl',
                    'value'      => $amount,
                    'base_value' => $baseAmount,
                    'label'      => __('%1 (Excl. Tax)', $label),
                ]),
                'tax'
            );
            $parent->addTotal(
                new DataObject([
                    'code'       => 'two_surcharge_incl',
                    'value'      => $amount + $tax,
                    'base_value' => $baseAmount + $baseTax,
                    'label'      => __('%1 (Incl. Tax)', $label),
                ]),
                'two_surcharge_excl'
            );

            return $this;
        }

        $parent->addTotalBefore(
            new DataObject([
                'code'       => 'two_surcharge',
                'value'      => $display->pick($mode, $amount, $tax),
                'base_value' => $display->pick($mode, $baseAmount, $baseTax),
                'label'      => $label,
            ]),
            'tax'
        );

        return $this;
    }

    /**
     * The document's own store, not the current one — admin views and emails
     * both render outside the store whose tax settings apply.
     *
     * @param mixed $source order, invoice or creditmemo
     * @return \Magento\Store\Model\Store|null
     */
    private function resolveStore($source)
    {
        return method_exists($source, 'getStore') ? $source->getStore() : null;
    }
}
