<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Two\Gateway\Model\Config;

/**
 * The one normalisation of a stored custom payment term, shared by the admin field's gate,
 * renderer and every backend model that reads it — two readings would disagree on a
 * hand-edited row and one of them would then delete it (ABN-522).
 */
class StoredTerm
{
    /**
     * Nothing worth showing: absent, empty, or a zero, which is not a term and reads as blank.
     *
     * @param mixed $configured
     */
    public static function isBlank($configured): bool
    {
        if (!is_scalar($configured)) {
            return true;
        }
        $trimmed = trim((string)$configured);

        return $trimmed === '' || preg_match('/^0+$/', $trimmed) === 1;
    }

    /**
     * The term the value denotes, or null where it denotes none — a run of digits over zero, so
     * leading zeros normalise to the same term and everything else denotes nothing.
     *
     * @param mixed $configured
     */
    public static function days($configured): ?int
    {
        if (!is_scalar($configured)) {
            return null;
        }
        $trimmed = trim((string)$configured);

        return preg_match('/^\d+$/', $trimmed) === 1 && (int)$trimmed > 0 ? (int)$trimmed : null;
    }

    /**
     * Stored but not a number of days. It has to stay visible and block the save: hiding it
     * would leave the merchant no way to correct it.
     *
     * @param mixed $configured
     */
    public static function isUnusable($configured): bool
    {
        return !self::isBlank($configured) && self::days($configured) === null;
    }
}
