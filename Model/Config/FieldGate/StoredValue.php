<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Two\Gateway\Model\Config\FieldGate;

/**
 * Configured when anything at all is stored — gates a deprecated field kept only for the
 * merchants who already carry a value (ABN-522).
 */
class StoredValue implements ConfiguredPredicateInterface
{
    public function isConfigured($stored): bool
    {
        return is_scalar($stored) && trim((string)$stored) !== '';
    }
}
