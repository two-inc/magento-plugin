<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Model\Webapi;

use Two\Gateway\Api\Webapi\CompanyLookupInterface;
use Two\Gateway\Service\Api\Adapter;

class CompanyLookup implements CompanyLookupInterface
{
    use UpstreamEnvelopeTrait;

    public function __construct(
        private readonly Adapter $adapter
    ) {
    }

    /**
     * @inheritDoc
     */
    public function search(string $country, string $query): string
    {
        $endpoint = self::SEARCH_ENDPOINT . '?' . http_build_query([
            'country' => strtoupper(trim($country)),
            'limit' => self::SEARCH_LIMIT,
            'offset' => 0,
            'q' => $query,
        ]);

        return $this->envelope($this->adapter->execute($endpoint, [], 'GET'));
    }

    /**
     * @inheritDoc
     */
    public function get(string $lookupId): string
    {
        $endpoint = self::SEARCH_ENDPOINT . '/' . rawurlencode($lookupId);

        return $this->envelope($this->adapter->execute($endpoint, [], 'GET'));
    }
}
