<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Model\Config\Backend;

use Magento\Framework\App\Config\Value;
use Magento\Framework\Exception\LocalizedException;
use Two\Gateway\Model\Config\Source\SurchargeTaxClass as SurchargeTaxClassSource;
use Two\Gateway\Model\Config\Source\SurchargeType;

/**
 * Server-side guard for the surcharge tax treatment selector.
 *
 * The selector never auto-defaults (see the source model); this
 * backend model is the enforcement half: while surcharges are enabled
 * the config save is rejected until the merchant has explicitly picked
 * a treatment. Enforced here — not just in admin JS — so CLI
 * `config:set`, API-driven saves and any theme quirk hit the same
 * rule.
 *
 * It also refuses the deprecated "Custom" treatment when no legacy
 * flat-rate value exists at the scope being saved: the Custom option
 * is a backward-compat carve-out for pre-existing merchants only, and
 * must not be creatable through a hand-crafted POST.
 *
 * Sibling config paths (surcharge_type / surcharge_tax_rate) are
 * derived from this field's own path so the rule is brand-aware —
 * synthesized brand forms save under payment/<brand_code>/ and get the
 * exact same enforcement.
 */
class SurchargeTaxClass extends Value
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
        $value = (string)$this->getValue();

        if ($value === '' && $this->isSurchargeEnabled()) {
            throw new LocalizedException(
                __(
                    'Please select a surcharge tax treatment. A surcharge method is enabled, '
                    . 'so the surcharge tax treatment must be chosen explicitly.'
                )
            );
        }

        if ($value === SurchargeTaxClassSource::CUSTOM && !$this->hasLegacyFlatRate()) {
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
     * Whether a surcharge method is enabled for the scope being saved.
     * Prefers the value posted in the same save request (fieldset
     * data); falls back to the stored config for partial saves.
     */
    private function isSurchargeEnabled(): bool
    {
        $surchargeType = $this->getFieldsetDataValue('surcharge_type');
        if ($surchargeType === null || $surchargeType === '') {
            $surchargeType = $this->getScopedSiblingValue('surcharge_type');
        }
        return $surchargeType !== null
            && $surchargeType !== ''
            && $surchargeType !== SurchargeType::NONE;
    }

    /**
     * Whether the deprecated flat rate genuinely exists at this scope.
     * Deliberately null/'' checks, never truthy: a configured rate of
     * 0 or "0.00" is still a real value (classic falsy-zero bug).
     */
    private function hasLegacyFlatRate(): bool
    {
        $rate = $this->getScopedSiblingValue('surcharge_tax_rate');
        return $rate !== null && $rate !== '';
    }

    /**
     * Read a sibling config key (same payment/<code>/ prefix as this
     * field) at the scope being saved.
     *
     * @return mixed
     */
    private function getScopedSiblingValue(string $key)
    {
        $path = preg_replace('#/[^/]+$#', '/' . $key, (string)$this->getPath());
        return $this->_config->getValue(
            $path,
            $this->getScope() ?: 'default',
            $this->getScopeCode()
        );
    }
}
