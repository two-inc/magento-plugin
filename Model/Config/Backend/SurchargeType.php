<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Model\Config\Backend;

use Magento\Framework\Exception\LocalizedException;

/**
 * Server-side guard on the Surcharge Method field.
 *
 * Enforces the same invariant as the guard on the treatment selector —
 * surcharge enabled implies a surcharge tax treatment is explicitly
 * chosen — from the other side of the pair. This is the effective
 * section-save guard: the admin config save posts every visible field
 * in the group, so this model is instantiated on every save of the
 * payment section, which is what catches a shop already sitting in the
 * enabled-with-blank-treatment state (and `config:set` on this path).
 */
class SurchargeType extends AbstractSurchargeTreatmentGuard
{
    /**
     * @inheritDoc
     *
     * @throws LocalizedException when a surcharge method is enabled and
     *         no surcharge tax treatment is selected.
     */
    public function beforeSave()
    {
        $this->assertTaxTreatmentSelected();

        return parent::beforeSave();
    }

    /**
     * This field IS the surcharge method: its own submitted value wins.
     */
    protected function getSurchargeTypeValue(): ?string
    {
        return (string)$this->getValue();
    }
}
