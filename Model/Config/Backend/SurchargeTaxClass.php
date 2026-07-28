<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Model\Config\Backend;

use Magento\Framework\Exception\LocalizedException;
use Two\Gateway\Model\Config\Source\SurchargeTaxClass as SurchargeTaxClassSource;

/**
 * Server-side guard for the surcharge tax treatment selector.
 *
 * The selector never auto-defaults (see the source model); this
 * backend model is the enforcement half: while surcharges are enabled
 * the config save is rejected until the merchant has explicitly picked
 * a treatment. The shared invariant lives in
 * {@see AbstractSurchargeTreatmentGuard} so the sibling guard on the
 * Surcharge Method field enforces exactly the same rule — see that
 * class for why one guard on this field alone is not enough.
 *
 * This model additionally refuses the deprecated "Custom" treatment
 * when no legacy flat-rate value exists at the scope being saved: the
 * Custom option is a backward-compat carve-out for pre-existing
 * merchants only, and must not be creatable through a hand-crafted
 * POST. That check belongs to this field alone.
 */
class SurchargeTaxClass extends AbstractSurchargeTreatmentGuard
{
    /**
     * @inheritDoc
     *
     * @throws LocalizedException when surcharges are enabled and no
     *         tax treatment is selected, or "Custom" is submitted
     *         without a pre-existing legacy flat rate.
     */
    public function beforeSave()
    {
        $this->assertTaxTreatmentSelected();

        if ((string)$this->getValue() === SurchargeTaxClassSource::CUSTOM && !$this->hasLegacyFlatRate()) {
            throw new LocalizedException(
                __(
                    'The "Custom flat rate" surcharge tax treatment is deprecated and only '
                    . 'available to merchants with a previously configured Surcharge Tax Rate. '
                    . 'Please select a tax class instead.'
                )
            );
        }

        return parent::beforeSave();
    }

    /**
     * This field IS the treatment: its own submitted value wins outright,
     * including an explicit blank, which must never fall back to whatever
     * happens to be stored.
     */
    protected function getTaxTreatmentValue(): ?string
    {
        return (string)$this->getValue();
    }
}
