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
 * Save-time guard for the product-page button's opt-in (TWO-25800).
 *
 * The runtime read already refuses an unrecognised value, but a read-path
 * refusal is discovered late and silently: the storefront simply has no button,
 * and only the log says why. This refuses the value where the merchant can see
 * it, which is the half of the module's fail-loud standard that speaks to a
 * person rather than to a log.
 *
 * It does not make the read-path check redundant. `config:set`, a `config.php`
 * import and a hand-edited row all reach the stored value without instantiating
 * a backend model, so the read remains the choke point; this is the early
 * warning, not the guarantee.
 */
class ProductButtonEnabled extends Value
{
    /**
     * @return $this
     * @throws LocalizedException
     */
    public function beforeSave()
    {
        // "Use Default" at a non-default scope is Magento's inherit path, and it
        // retires the row rather than storing anything, so there is no value to
        // judge. It is the ONLY route that may bypass this check: an empty
        // string arriving any other way is a submitted value, not an absence,
        // and the read path already refuses it.
        if ($this->getData('inherit')) {
            return parent::beforeSave();
        }

        $value = $this->getValue();

        if ($value === null) {
            return parent::beforeSave();
        }

        // Shape before value. An array reaching (string) raises an
        // array-to-string cast, which Magento's error handler turns into an
        // unexpected exception — so the merchant gets a stack trace instead of
        // the refusal this class exists to give them. The type name stands in
        // for a value that has none.
        $reported = is_scalar($value) ? (string)$value : gettype($value);

        if (!is_scalar($value) || !in_array($reported, ['0', '1'], true)) {
            throw new LocalizedException(
                __(
                    'Show buy button on product pages accepts only Yes or No; "%1" is not a value it understands.',
                    $reported
                )
            );
        }

        return parent::beforeSave();
    }
}
