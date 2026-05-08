<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Model\Config\Backend;

use Magento\Framework\App\Config\Value;

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
     */
    public function beforeSave()
    {
        $value = $this->getValue();
        if (is_array($value)) {
            $value = array_filter(array_map('intval', $value));
            sort($value);
            $this->setValue(implode(',', $value));
        }
        return parent::beforeSave();
    }
}
