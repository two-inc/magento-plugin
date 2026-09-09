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
 * Deprecated field, retained only to carry a legacy custom term through an upgrade: the stored
 * value may be removed but never replaced (ABN-522).
 */
class PaymentTermsCustomDays extends Value
{
    /**
     * @inheritDoc
     *
     * @throws LocalizedException when the post carries a value other than the stored one.
     */
    public function beforeSave()
    {
        $posted = trim((string)$this->getValue());
        $stored = trim((string)$this->getOldValue());

        if ($posted !== '' && $posted !== $stored) {
            throw new LocalizedException(__('Custom payment terms (days) can only be removed, not changed.'));
        }

        $this->setValue($posted);

        return parent::beforeSave();
    }
}
