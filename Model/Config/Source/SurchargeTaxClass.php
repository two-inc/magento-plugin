<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;
use Magento\Tax\Model\TaxClass\Source\Product as ProductTaxClassSource;

/**
 * Product Tax Class options for the surcharge tax class selector.
 *
 * Mirrors Magento core's own tax/classes/shipping_tax_class dropdown
 * (Magento\Tax\Model\TaxClass\Source\Product) but prepends an explicit
 * "legacy flat rate" option with an EMPTY value. This matters for
 * upgrade safety: merchants who configured the flat Surcharge Tax Rate
 * before this field existed must not be silently flipped onto the tax
 * rules engine (or onto "None" = zero tax) just because they re-saved
 * the config page — the empty value is both the unset default and the
 * explicit opt-out, and Repository::getSurchargeTaxClassId() maps it
 * to null (= use the flat rate).
 *
 * The delegate's option list includes "None" (value 0) plus every
 * Product Tax Class; selecting a class routes surcharge tax through
 * TaxCalculationInterface with full destination/rule resolution.
 */
class SurchargeTaxClass implements OptionSourceInterface
{
    /**
     * @var ProductTaxClassSource
     */
    private $productTaxClassSource;

    public function __construct(ProductTaxClassSource $productTaxClassSource)
    {
        $this->productTaxClassSource = $productTaxClassSource;
    }

    /**
     * @inheritDoc
     */
    public function toOptionArray(): array
    {
        $options = [
            ['value' => '', 'label' => __('Use flat Surcharge Tax Rate below (legacy)')],
        ];
        foreach ($this->productTaxClassSource->getAllOptions(true) as $option) {
            $options[] = $option;
        }
        return $options;
    }
}
