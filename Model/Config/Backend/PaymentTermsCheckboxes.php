<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Model\Config\Backend;

use Magento\Framework\App\Config\Value;
use Magento\Framework\Exception\LocalizedException;

/**
 * Backend model for payment terms checkboxes.
 *
 * Converts the array of checked values into a comma-separated string
 * for storage, matching the existing multiselect format.
 */
class PaymentTermsCheckboxes extends Value
{
    /**
     * @inheritDoc
     *
     * @throws LocalizedException when no payment term is selected and no custom
     *         term is entered — a selection is mandatory.
     */
    public function beforeSave()
    {
        $raw = $this->getValue();
        if (is_array($raw)) {
            $value = array_filter(array_map('intval', $raw));
        } else {
            // CSV string (e.g. a CLI config:set) — normalise identically.
            $value = array_filter(array_map('intval', explode(',', (string)$raw)));
        }
        sort($value);

        // A selection is mandatory. The optional custom term (sibling field)
        // also satisfies it, so a single off-preset term may be offered alone.
        $custom = (int)$this->getFieldsetDataValue('payment_terms_duration_days');
        if (count($value) === 0 && $custom <= 0) {
            throw new LocalizedException(
                __('Select at least one payment term or enter a custom term.')
            );
        }

        $this->setValue(implode(',', $value));
        return parent::beforeSave();
    }
}
