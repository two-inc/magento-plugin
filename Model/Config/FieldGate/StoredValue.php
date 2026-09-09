<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Two\Gateway\Model\Config\FieldGate;

use Two\Gateway\Model\Config\StoredTerm;

/**
 * Configured when a value worth showing is stored — gates a deprecated field kept only for the
 * merchants who already carry one. A zero reads as blank; junk does not, so it stays correctable
 * (ABN-522).
 */
class StoredValue implements ConfiguredPredicateInterface
{
    public function isConfigured($stored): bool
    {
        return !StoredTerm::isBlank($stored);
    }
}
