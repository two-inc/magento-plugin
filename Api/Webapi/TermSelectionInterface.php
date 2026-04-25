<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Api\Webapi;

interface TermSelectionInterface
{
    /**
     * Set the buyer's selected payment term and recalculate quote totals.
     *
     * @api
     *
     * @param string $cartId
     * @param int $termDays
     * @return mixed
     */
    public function selectTerm(string $cartId, int $termDays): array;
}
