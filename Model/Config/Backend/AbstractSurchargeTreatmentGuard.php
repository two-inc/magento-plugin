<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Model\Config\Backend;

use Magento\Framework\App\Config\Value;
use Magento\Framework\Exception\LocalizedException;
use Two\Gateway\Model\Config\Source\SurchargeType as SurchargeTypeSource;

/**
 * Shared save-time enforcement of the surcharge tax treatment invariant:
 * while a surcharge method is enabled, a surcharge tax treatment must be
 * explicitly selected.
 *
 * A Magento field backend model is only instantiated when its own field is
 * part of the save, so a single guard on the treatment selector could never
 * see a save that only touched the Surcharge Method (or any unrelated field
 * in the section). Both the Surcharge Method field and the Surcharge Tax
 * Treatment field therefore extend this class: the admin section save posts
 * every visible field in the group, so the Surcharge Method guard runs on
 * every admin save of the payment section — including saves of a shop that
 * is already sitting in the enabled-with-blank-treatment state.
 *
 * "Explicitly selected" deliberately includes the deprecated legacy flat
 * rate (payment/<code>/surcharge_tax_rate): merchants configured before the
 * tax-rule selector existed have made a choice, and must not be blocked.
 * All emptiness checks are null/'' checks, never truthy — a configured rate
 * of 0 or "0.00" is a real value.
 *
 * Sibling config paths are derived from the field's own path so the rule is
 * brand-aware: synthesized brand forms save under payment/<brand_code>/ and
 * get identical enforcement.
 *
 * Nothing here runs on config page load — this is the save path only, and it
 * throws a LocalizedException, which Magento renders as an admin error
 * message on the section it was saving. The Surcharge Tax Treatment field
 * lives in that same section, so a rejected merchant can always pick a
 * treatment and save again.
 */
abstract class AbstractSurchargeTreatmentGuard extends Value
{
    /**
     * Reject the save when a surcharge is enabled at this scope but no
     * surcharge tax treatment has been chosen.
     *
     * @throws LocalizedException
     */
    protected function assertTaxTreatmentSelected(): void
    {
        if ($this->isSurchargeEnabled() && !$this->isTaxTreatmentSelected()) {
            throw new LocalizedException(
                __(
                    'Please select a Surcharge Tax Treatment. A surcharge method is enabled '
                    . '(see the Surcharge Method field), so the Surcharge Tax Treatment field '
                    . 'must be chosen explicitly before this configuration can be saved.'
                )
            );
        }
    }

    /**
     * Whether a surcharge method is enabled for the scope being saved.
     */
    protected function isSurchargeEnabled(): bool
    {
        $surchargeType = $this->getSurchargeTypeValue();
        if ($surchargeType === null || $surchargeType === '') {
            $surchargeType = $this->getScopedSiblingValue('surcharge_type');
        }

        return $surchargeType !== null
            && $surchargeType !== ''
            && $surchargeType !== SurchargeTypeSource::NONE;
    }

    /**
     * Whether the merchant has explicitly chosen how the surcharge is taxed.
     * A pre-existing legacy flat rate counts as a choice.
     */
    protected function isTaxTreatmentSelected(): bool
    {
        $treatment = $this->getTaxTreatmentValue();
        if ($treatment !== null && $treatment !== '') {
            return true;
        }

        return $this->hasLegacyFlatRate();
    }

    /**
     * Surcharge method for this save. Prefers the value posted in the same
     * request (fieldset data); null/'' means "not part of this save" and the
     * caller falls back to stored config.
     */
    protected function getSurchargeTypeValue(): ?string
    {
        $posted = $this->getFieldsetDataValue('surcharge_type');

        return $posted === null ? null : (string)$posted;
    }

    /**
     * Surcharge tax treatment for this save. Prefers the value posted in the
     * same request; falls back to stored config for partial saves. A posted
     * empty string is a real (blank) submission, not an absent field.
     */
    protected function getTaxTreatmentValue(): ?string
    {
        $posted = $this->getFieldsetDataValue('surcharge_tax_class');
        if ($posted !== null) {
            return (string)$posted;
        }

        $stored = $this->getScopedSiblingValue('surcharge_tax_class');

        return $stored === null ? null : (string)$stored;
    }

    /**
     * Whether the deprecated flat rate genuinely exists at this scope.
     * Deliberately null/'' checks, never truthy: a configured rate of
     * 0 or "0.00" is still a real value (classic falsy-zero bug).
     */
    protected function hasLegacyFlatRate(): bool
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
    protected function getScopedSiblingValue(string $key)
    {
        $path = preg_replace('#/[^/]+$#', '/' . $key, (string)$this->getPath());
        // scope_id, not scope_code: the admin form save sets both, but
        // CLI config:set (PreparedValueFactory) only sets scope/scope_id,
        // and ScopeConfigInterface::getValue resolves numeric ids fine.
        return $this->_config->getValue(
            $path,
            $this->getScope() ?: 'default',
            $this->getScopeId()
        );
    }
}
