<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace ABN\Gateway\Api\Webapi;

interface SoleTraderInterface
{
    public const DELEGATION_TOKEN_ENDPOINT = '/registry/v1/delegation';
    public const AUTOFILL_TOKEN_ENDPOINT = '/autofill/v1/delegation';

    /**
     * @api
     *
     * @param string $cartId
     * @return array
     */
    public function getTokens(string $cartId): array;
}
