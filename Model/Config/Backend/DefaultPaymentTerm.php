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
                'Default payment term %1 days is not one of the terms you offer: %2 days.',
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

        $custom = (int)$this->getFieldsetDataValue('payment_terms_duration_days');
        if ($custom > 0) {
            $terms[] = $custom;
        }

        $terms = array_values(array_unique($terms));
        sort($terms);

        return $terms;
    }
}
