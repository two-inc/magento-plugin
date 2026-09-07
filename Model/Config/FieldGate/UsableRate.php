<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Two\Gateway\Model\Config\FieldGate;

use Two\Gateway\Model\Config\StoredRate;

/**
 * Configured when a usable rate is stored — a genuine 0% included, junk not.
 */
class UsableRate implements ConfiguredPredicateInterface
{
    public function isConfigured($stored): bool
    {
        return StoredRate::normalise($stored) !== null;
    }
}
