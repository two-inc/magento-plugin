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
 *
 * It refuses a NEWLY submitted core "None" (class id 0) on exactly the
 * same reasoning (TWO-25279). The source model suppresses that option,
 * but a suppressed option is a UI rule only: without this check the
 * never-taxed treatment stays creatable by anyone who crafts the POST,
 * and the "an untaxed surcharge must be a tax rule the merchant
 * configured" rule would be advisory. A scope that ALREADY stores 0 can
 * still submit it, which is what lets a pre-existing or migrated scope
 * keep saving.
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
     * @throws LocalizedException when surcharges are enabled and no
     *         tax treatment is selected, when "None" is newly submitted
     *         at a scope that does not already store it, or when "Custom"
     *         is submitted without a pre-existing legacy flat rate.
     */
    public function beforeSave()
    {
        $this->assertTaxTreatmentSelected();

        if ((string)$this->getValue() === SurchargeTaxClassSource::NEVER_TAXED_CLASS_ID
            && !$this->neverTaxedIsAlreadyStored()
        ) {
            throw new LocalizedException(
                __(
                    'The "None" surcharge tax treatment is no longer available: an untaxed '
                    . 'surcharge must be a tax rule you configured. Create a Tax Rule with a 0% '
                    . 'rate and select its Product Tax Class instead.'
                )
            );
        }

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

    /**
     * Whether core "None" is already the stored treatment at the scope being
     * saved — read through the sibling helper so it is scope-anchored and
     * inheritance-aware, matching what the source model offered.
     */
    private function neverTaxedIsAlreadyStored(): bool
    {
        $stored = $this->getScopedSiblingValue('surcharge_tax_class');

        return $stored !== null
            && (string)$stored === SurchargeTaxClassSource::NEVER_TAXED_CLASS_ID;
    }
}
