<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Api\Webapi;

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

    /**
     * The buyer company types the Two registry supports for a billing
     * country (e.g. ['SOLE_TRADER']). An empty list means registered
     * businesses only. Fail-soft: resolves to an empty list on any
     * registry error.
     *
     * @api
     *
     * @param string $countryCode ISO 3166-1 alpha-2 country code
     * @return string[]
     */
    public function getSupportedCompanyTypes(string $countryCode): array;
}
