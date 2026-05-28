<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Model\Config\Backend;

use Magento\Framework\App\Config\Value;

/**
 * Backend model for numeric config fields that should accept the
 * admin locale's decimal separator on input. Storage stays
 * canonical period-decimal; this normalises comma → period on
 * save so downstream (float) casts see "21.5" not "21," (which
 * PHP truncates to 21).
 *
 * Mirrors the comma → period pass already performed in
 * Two\Gateway\Model\Config\Backend\SurchargeGrid::afterSave(),
 * but for single scalar fields (no per-term iteration).
 */
class LocaleDecimal extends Value
{
    /**
     * @inheritDoc
     */
    public function beforeSave()
    {
        $value = $this->getValue();
        if (is_string($value)) {
            $this->setValue(str_replace(',', '.', $value));
        }
        return parent::beforeSave();
    }
}
