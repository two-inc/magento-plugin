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
     * @throws LocalizedException when the merchant clears every term after
     *         terms were previously configured.
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

        // The optional custom term (sibling field) also satisfies the
        // "offer at least one term" rule.
        $custom = (int)$this->getFieldsetDataValue('payment_terms_duration_days');
        $newHasTerms = count($value) > 0 || $custom > 0;

        // An empty selection is a valid "use the account default term" state,
        // but the merchant may not transition a configured gateway into it —
        // clearing all terms is what previously surfaced a system error.
        $oldHasTerms = (string)$this->getOldValue() !== '';

        if (!$newHasTerms && $oldHasTerms) {
            throw new LocalizedException(
                __('Select at least one payment term, or enter a custom term. Clearing every term is not allowed once terms have been configured.')
            );
        }

        $this->setValue(implode(',', $value));
        return parent::beforeSave();
    }
}
