<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Api\Webapi;

/**
 * Server-side proxy for the company registry lookups, so the merchant API key
 * and firewall token never reach the browser.
 */
interface CompanyLookupInterface
{
    public const SEARCH_ENDPOINT = '/companies/v2/company';

    /** Upper bound on rows a single search may ask the registry for. */
    public const SEARCH_LIMIT = 50;

    /**
     * Search the registry for companies matching a buyer's query.
     *
     * Anonymous route — guest checkout requires it.
     *
     * @api
     *
     * @param string $country ISO 3166-1 alpha-2 country code
     * @param string $query the buyer's search term
     * @return string JSON-encoded {ok: bool, status: int, body: object}
     */
    public function search(string $country, string $query): string;

    /**
     * Fetch one registry company record by its search-result id.
     *
     * @api
     *
     * @param string $lookupId id from a search result row
     * @return string JSON-encoded {ok: bool, status: int, body: object}
     */
    public function get(string $lookupId): string;
}
