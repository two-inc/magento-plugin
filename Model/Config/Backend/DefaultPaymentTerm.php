<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Model\Config\Backend;

use Magento\Framework\App\Config\Value;
use Magento\Framework\Exception\LocalizedException;
use Two\Gateway\Model\Config\StoredTerm;

/**
 * Refuses a default term outside the terms saved alongside it (ABN-495).
 */
class DefaultPaymentTerm extends Value
{
    /**
     * @inheritDoc
     */
    public function beforeSave()
    {
        $default = (int)$this->getValue();
        $enabled = $this->enabledTerms();
        if ($default > 0 && $enabled !== [] && !in_array($default, $enabled, true)) {
            throw new LocalizedException(__(
                'Default payment terms names %1 days, which is not one of the terms you offer: %2 days.'
                . ' Choose one of those in this same save.',
                $default,
                implode(', ', $enabled)
            ));
        }

        return parent::beforeSave();
    }

    /**
     * Empty where the group is absent from the post (a CLI config:set), leaving nothing to validate against.
     */
    private function enabledTerms(): array
    {
        // fieldset_data holds the whole group before any beforeSave() runs, so sibling reads are order-independent (TWO-25498).
        $posted = $this->getFieldsetDataValue('payment_terms');
        $terms = array_filter(array_map(
            'intval',
            is_array($posted) ? $posted : explode(',', (string)$posted)
        ));

        $custom = StoredTerm::days($this->getFieldsetDataValue('payment_terms_duration_days'));
        if ($custom !== null) {
            $terms[] = $custom;
        }

        $terms = array_values(array_unique($terms));
        sort($terms);

        return $terms;
    }
}
