<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Model\Webapi;

use Magento\Checkout\Model\Session as CheckoutSession;
use Two\Gateway\Api\Log\RepositoryInterface as LogRepository;
use Two\Gateway\Api\Webapi\CompanyLookupInterface;
use Two\Gateway\Service\Api\Adapter;
use Two\Gateway\Service\Merchant\ApiKeyStatus;
use Two\Gateway\Service\RateLimiter;

class CompanyLookup implements CompanyLookupInterface
{
    use UpstreamEnvelopeTrait;

    /** Sized for typing: the panel debounces, one buyer still fires several searches per company. */
    private const SEARCH_LIMIT_PER_MINUTE = 60;

    /** One detail fetch per row the buyer picks. */
    private const DETAIL_LIMIT_PER_MINUTE = 30;

    /** Fetched once per page load and memoised client-side. */
    private const SUPPORTED_COUNTRIES_LIMIT_PER_MINUTE = 30;

    private const WINDOW_SECONDS = 60;

    /** Longer than any registry name; anything past it is not a search term. */
    private const MAX_QUERY_LENGTH = 120;

    /** ISO 3166-1 alpha-2. */
    private const COUNTRY_PATTERN = '/^[A-Z]{2}$/';

    /** Registry lookup ids are short opaque tokens. */
    private const MAX_LOOKUP_ID_LENGTH = 128;

    public function __construct(
        private readonly Adapter $adapter,
        private readonly ApiKeyStatus $apiKeyStatus,
        private readonly RateLimiter $rateLimiter,
        private readonly LogRepository $logRepository,
        private readonly CheckoutSession $checkoutSession
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

        $country = strtoupper(trim($country));
        $query = trim($query);
        if (preg_match(self::COUNTRY_PATTERN, $country) !== 1
            || $query === ''
            || mb_strlen($query) > self::MAX_QUERY_LENGTH
        ) {
            return $this->refusal(400, (string)__('Invalid company search request.'));
        }

        $endpoint = self::SEARCH_ENDPOINT . '?' . http_build_query(array_merge(
            [
                'country' => $country,
                'limit' => self::SEARCH_LIMIT,
                'offset' => 0,
                'q' => $query,
            ],
            $this->merchantParams()
        ));

        return $this->envelope($this->adapter->executeWithStatus($endpoint, [], 'GET', $this->quoteStoreId()));
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

        if (mb_strlen($lookupId) > self::MAX_LOOKUP_ID_LENGTH) {
            return $this->refusal(400, (string)__('Invalid company lookup request.'));
        }

        $endpoint = self::SEARCH_ENDPOINT . '/' . rawurlencode($lookupId);
        $merchant = $this->merchantParams();
        if ($merchant) {
            $endpoint .= '?' . http_build_query($merchant);
        }

        return $this->envelope($this->adapter->executeWithStatus($endpoint, [], 'GET', $this->quoteStoreId()));
    }

    /**
     * @inheritDoc
     */
    public function supportedCountries(): string
    {
        $this->rateLimiter->assertWithinLimit(
            'two_company_supported_countries',
            self::SUPPORTED_COUNTRIES_LIMIT_PER_MINUTE,
            self::WINDOW_SECONDS
        );

        return $this->envelope($this->adapter->executeWithStatus(
            self::SUPPORTED_COUNTRIES_ENDPOINT,
            [],
            'GET',
            $this->quoteStoreId()
        ));
    }

    /**
     * Server-resolved only — a browser-supplied merchant is what this proxy
     * exists to stop. Omitted while the key does not verify, rather than
     * failing a lookup the buyer is mid-typing.
     *
     * @return array<string,string>
     */
    private function merchantParams(): array
    {
        $status = $this->apiKeyStatus->getStatus();
        $shortName = $status['merchant']['short_name'] ?? null;

        if ($status['status'] !== ApiKeyStatus::OK || !is_string($shortName) || $shortName === '') {
            return [];
        }

        return ['merchant' => $shortName];
    }
}
