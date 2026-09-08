<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Two\Gateway\Model\Config\FieldGate;

/**
 * Whether a stored admin-field value counts as "already configured".
 */
interface ConfiguredPredicateInterface
{
    /**
     * @param mixed $stored Effective value of the field's own config path at the scope being edited.
     */
    public function isConfigured($stored): bool;
}
