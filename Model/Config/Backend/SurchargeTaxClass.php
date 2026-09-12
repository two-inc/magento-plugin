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
 * Surcharge method field enforces exactly the same rule — see that
 * class for why one guard on this field alone is not enough.
 *
 * This model additionally refuses the deprecated "Custom" treatment
 * when no legacy flat-rate value exists at the scope being saved: the
 * Custom option is a backward-compat carve-out for pre-existing
 * merchants only, and must not be creatable through a hand-crafted
 * POST. That check belongs to this field alone.
 *
 * It refuses any never-taxed treatment outright on the same reasoning
 * (TWO-25279). Removing those options from the source model is a UI rule
 * only; without this check the never-taxed treatment would stay
 * creatable by anyone who crafts the POST, and "an untaxed surcharge
 * must be a Tax Rule the merchant configured" would be advisory. There
 * is no already-stored exemption: a scope sitting on such a value is
 * told to fix it (see the field's frontend model), and no save of the
 * section is accepted until it does — including a blank submission,
 * which would otherwise overwrite the stored value and hide the state.
 *
 * Real coverage: every admin config-section save, at any scope. NOT
 * `bin/magento config:set`, NOT "Use Default" / inherit, NOT direct
 * core_config_data writes — an earlier comment here claimed CLI
 * coverage and was wrong; {@see AbstractSurchargeTreatmentGuard} has
 * the verified detail.
 */
class SurchargeTaxClass extends AbstractSurchargeTreatmentGuard
{
    /**
     * @inheritDoc
     *
     * @throws LocalizedException when a stored never-taxed treatment is not
     *         replaced by this save, when surcharges are enabled and no tax
     *         treatment is selected, when a never-taxed treatment is
     *         submitted, or when "Custom" is submitted without a
     *         pre-existing legacy flat rate.
     */
    public function beforeSave()
    {
        $this->assertTaxTreatmentSelected();

        if ($this->neverTaxedTreatment->isNeverTaxed((string)$this->getValue())) {
            throw new LocalizedException(
                __(
                    'That surcharge tax treatment leaves the surcharge untaxed in every '
                    . 'jurisdiction and is no longer available. Create a Tax Rule with a 0% rate '
                    . 'and select its Product Tax Class instead.'
                )
            );
        }

        $this->assertStoredTreatmentIsReplaced();

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
    protected function getSubmittedTaxTreatment(): ?string
    {
        return (string)$this->getValue();
    }
}
