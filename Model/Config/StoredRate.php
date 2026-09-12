<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Two\Gateway\Model\Config;

/**
 * Shared read-path normalisation for a stored percentage rate.
 */
class StoredRate
{
    /**
     * Junk resolves to absent so a hand-edited row cannot become a declared 0%; a genuine 0 stays a declaration.
     *
     * @param mixed $configured
     */
    public static function normalise($configured): ?float
    {
        if (!is_scalar($configured) || $configured === '' || !is_numeric($configured)) {
            return null;
        }
        $rate = (float)$configured;
        if (!is_finite($rate) || $rate < 0) {
            return null;
        }
        return $rate;
    }
}
