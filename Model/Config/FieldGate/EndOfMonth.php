<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Two\Gateway\Model\Config\FieldGate;

use Two\Gateway\Model\Config\Source\PaymentTermsType;

/**
 * Configured only where End of Month is the stored choice (TWO-25656).
 */
class EndOfMonth implements ConfiguredPredicateInterface
{
    public function isConfigured($stored): bool
    {
        return is_scalar($stored) && trim((string)$stored) === PaymentTermsType::END_OF_MONTH;
    }
}
