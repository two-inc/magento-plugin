<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Model\Webapi;

use Two\Gateway\Api\Webapi\CompanyLookupInterface;
use Two\Gateway\Service\Api\Adapter;
use Two\Gateway\Service\RateLimiter;

class CompanyLookup implements CompanyLookupInterface
{
    use UpstreamEnvelopeTrait;

    /** Sized for typing: the panel debounces, one buyer still fires several searches per company. */
    private const SEARCH_LIMIT_PER_MINUTE = 60;

    /** One detail fetch per row the buyer picks. */
    private const DETAIL_LIMIT_PER_MINUTE = 30;

    private const WINDOW_SECONDS = 60;

    public function __construct(
        private readonly Adapter $adapter,
        private readonly RateLimiter $rateLimiter
    ) {
    }

    /**
     * @inheritDoc
     */
    public function search(string $country, string $query): string
    {
        $this->rateLimiter->assertWithinLimit(
            'two_company_search',
            self::SEARCH_LIMIT_PER_MINUTE,
            self::WINDOW_SECONDS
        );

        $endpoint = self::SEARCH_ENDPOINT . '?' . http_build_query([
            'country' => strtoupper(trim($country)),
            'limit' => self::SEARCH_LIMIT,
            'offset' => 0,
            'q' => $query,
        ]);

        return $this->envelope($this->adapter->executeWithStatus($endpoint, [], 'GET'));
    }

    /**
     * @inheritDoc
     */
    public function get(string $lookupId): string
    {
        $this->rateLimiter->assertWithinLimit(
            'two_company_detail',
            self::DETAIL_LIMIT_PER_MINUTE,
            self::WINDOW_SECONDS
        );

        $endpoint = self::SEARCH_ENDPOINT . '/' . rawurlencode($lookupId);

        return $this->envelope($this->adapter->executeWithStatus($endpoint, [], 'GET'));
    }
}
