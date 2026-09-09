<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Model\Config\Backend;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Value;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Registry;
use Two\Gateway\Model\Config\NeverTaxedTreatment;
use Two\Gateway\Model\Config\Source\SurchargeType as SurchargeTypeSource;

/**
 * Shared save-time enforcement of the surcharge tax treatment invariant:
 * while a surcharge method is enabled, a surcharge tax treatment must be
 * explicitly selected.
 *
 * A Magento field backend model is only instantiated when its own field is
 * part of the save, so a single guard on the treatment selector could never
 * see a save that only touched the Surcharge method (or any unrelated field
 * in the section). Both the Surcharge method field and the Surcharge tax
 * Treatment field therefore extend this class: the admin section save posts
 * every visible field in the group, so the Surcharge method guard runs on
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
 * get identical enforcement. That enforcement only exists where the
 * backend_model is wired, and an overlay install renders ONLY the synthesized
 * form (Plugin\Config\Structure\HidePaymentSection hides the static Two
 * sections), so both etc/adminhtml/system.xml and
 * etc/adminhtml/brand_form_template.xml must carry it — ABN-497 was this
 * wiring missing from the template.
 *
 * Nothing here runs on config page load — this is the save path only, and it
 * throws a LocalizedException, which Magento renders as an admin error
 * message on the section it was saving. The save transaction rolls back as a
 * whole, so a rejected save writes nothing. The Surcharge tax treatment field
 * lives in that same section, so a rejected merchant can always pick a
 * treatment and save again.
 *
 * Known write paths this does NOT cover (verified against Magento 2.4.6, not
 * an oversight — a field backend model is the wrong hook for them):
 *  - "Use Default" (inherit) at website/store scope. Magento routes inherited
 *    fields through the delete transaction, so beforeDelete runs, not
 *    beforeSave. Inheriting the treatment away while the inherited surcharge
 *    method is enabled is therefore accepted.
 *  - `bin/magento config:set`. It addresses fields by config path, and
 *    Magento\Config\Model\Config::setDataByPath() reads that as
 *    section/group/field — which does not resolve to a system.xml element for
 *    these fields, so the generic Value backend model is used and no field
 *    backend model of ours runs at all.
 *  - Direct core_config_data / config writer writes, which bypass the config
 *    model entirely by design.
 */
abstract class AbstractSurchargeTreatmentGuard extends Value
{
    /**
     * @var NeverTaxedTreatment
     */
    protected $neverTaxedTreatment;

    public function __construct(
        Context $context,
        Registry $registry,
        ScopeConfigInterface $config,
        TypeListInterface $cacheTypeList,
        NeverTaxedTreatment $neverTaxedTreatment,
        ?AbstractResource $resource = null,
        ?AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        parent::__construct($context, $registry, $config, $cacheTypeList, $resource, $resourceCollection, $data);
        $this->neverTaxedTreatment = $neverTaxedTreatment;
    }

    /**
     * Reject a save that would leave a stored never-taxed treatment in place:
     * a blank submission used to overwrite it with an empty string, silently
     * changing how the surcharge is taxed and erasing the state the field's
     * warning renderer reads (ABN-497). Not gated on the surcharge being
     * enabled, because the submitted-value refusal it completes is not either.
     *
     * Only a save the treatment field is PART of can be refused. A save
     * without it cannot overwrite the stored value, and refusing one would
     * brick the whole section for a brand that suppresses the field or a scope
     * inheriting it — neither offers the merchant a control to fix.
     *
     * @throws LocalizedException
     */
    protected function assertStoredTreatmentIsReplaced(): void
    {
        $submitted = $this->getSubmittedTaxTreatment();
        if ($submitted === null) {
            return;
        }

        if (!$this->neverTaxedTreatment->isNeverTaxed((string)$this->getScopedSiblingValue('surcharge_tax_class'))) {
            return;
        }

        if ($submitted !== '' && !$this->neverTaxedTreatment->isNeverTaxed($submitted)) {
            return;
        }

        throw new LocalizedException(
            __(
                'The saved Surcharge tax treatment leaves the surcharge untaxed in every '
                . 'jurisdiction and is no longer available. Select a Surcharge tax '
                . 'treatment to save this configuration. To leave the surcharge untaxed, '
                . 'create a Tax Rule with a 0% rate and select its Product Tax Class.'
            )
        );
    }

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
                    'Please select a surcharge tax treatment. A surcharge method is enabled '
                    . '(see the Surcharge method field), so the Surcharge tax treatment field '
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
     * Surcharge tax treatment submitted by this save, with no fallback to
     * stored config. null means the treatment field is not part of the save;
     * an empty string is a real (blank) submission.
     */
    protected function getSubmittedTaxTreatment(): ?string
    {
        $posted = $this->getFieldsetDataValue('surcharge_tax_class');

        return $posted === null ? null : (string)$posted;
    }

    /**
     * Surcharge tax treatment this scope ends up with: the submitted value,
     * or the stored one for a save the treatment field is not part of.
     */
    protected function getTaxTreatmentValue(): ?string
    {
        $submitted = $this->getSubmittedTaxTreatment();
        if ($submitted !== null) {
            return $submitted;
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
        // PreparedValueFactory-driven saves (app:config:import and friends)
        // only set scope/scope_id, and ScopeConfigInterface::getValue
        // resolves numeric ids fine.
        return $this->_config->getValue(
            $path,
            $this->getScope() ?: 'default',
            $this->getScopeId()
        );
    }
}
