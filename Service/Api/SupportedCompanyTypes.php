<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Service\Api;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Two\Gateway\Api\Log\RepositoryInterface as LogRepository;

/**
 * Resolves the buyer company types the Two registry supports for a
 * billing country, from GET /registry/v1/supported-company-types/{ISO}.
 *
 * The endpoint lists only the company types that must be enrolled via
 * the registry before they can buy (sole traders). Registered
 * businesses need no enrollment and are supported in every country Two
 * operates in, so they are deliberately omitted: an empty list is a
 * legitimate answer meaning business-only checkout for that country.
 *
 * Mirrors the WooCommerce / PrestaShop plugins' fetch+cache+fail-soft
 * protocol (WC_Twoinc_Sole_Trader / TwoSoleTrader):
 * - a successful answer (including a genuine empty list) is cached for
 *   CACHE_LIFETIME seconds, matching the endpoint's own Cache-Control
 *   max-age, keyed by country;
 * - a fetch ERROR (network, non-200, malformed body) is fail-soft for
 *   the current lookup — resolves to an empty list so the checkout
 *   never blocks and the sole-trader option simply doesn't render —
 *   but is deliberately NOT cached, so a transient registry blip does
 *   not suppress the option for the rest of the TTL window. It is
 *   memoized per request so one page view never pays the round-trip
 *   twice.
 */
class SupportedCompanyTypes
{
    public const SOLE_TRADER = 'SOLE_TRADER';

    private const ENDPOINT = '/registry/v1/supported-company-types/%s';
    private const CACHE_KEY_PREFIX = 'two_gateway_supported_company_types_';
    private const CACHE_LIFETIME = 3600;

    /**
     * @var Adapter
     */
    private $apiAdapter;

    /**
     * @var CacheInterface
     */
    private $cache;

    /**
     * @var Json
     */
    private $json;

    /**
     * @var LogRepository
     */
    private $logRepository;

    /**
     * Per-request memo keyed by country. Holds ['types' => string[]]
     * wrappers so a resolved empty list is distinguishable from "not
     * yet resolved".
     *
     * @var array<string,array{types: array}>
     */
    private $memo = [];

    public function __construct(
        Adapter $apiAdapter,
        CacheInterface $cache,
        Json $json,
        LogRepository $logRepository
    ) {
        $this->apiAdapter = $apiAdapter;
        $this->cache = $cache;
        $this->json = $json;
        $this->logRepository = $logRepository;
    }

    /**
     * The registry-enrollable company types for a billing country.
     *
     * @param string $countryCode ISO 3166-1 alpha-2, any case
     * @return string[] e.g. ['SOLE_TRADER']; empty = registered businesses only
     */
    public function getForCountry(string $countryCode): array
    {
        $countryCode = strtoupper(trim($countryCode));
        if (!preg_match('/^[A-Z]{2}$/', $countryCode)) {
            return [];
        }

        if (isset($this->memo[$countryCode])) {
            return $this->memo[$countryCode]['types'];
        }

        $cacheKey = self::CACHE_KEY_PREFIX . $countryCode;
        $cached = $this->cache->load($cacheKey);
        if ($cached !== false) {
            $wrapper = $this->json->unserialize($cached);
            $this->memo[$countryCode] = $wrapper;
            return $wrapper['types'];
        }

        $types = $this->fetch($countryCode);

        // Memoize either way so a single request never pays the
        // round-trip twice; persist only a successful answer to the
        // cross-request cache so a failure retries on the next request.
        $this->memo[$countryCode] = ['types' => $types ?? []];
        if ($types !== null) {
            $this->cache->save(
                $this->json->serialize(['types' => $types]),
                $cacheKey,
                [],
                self::CACHE_LIFETIME
            );
        }

        return $this->memo[$countryCode]['types'];
    }

    /**
     * Whether the registry supports sole-trader enrollment for a country.
     */
    public function isSoleTraderSupported(string $countryCode): bool
    {
        return in_array(self::SOLE_TRADER, $this->getForCountry($countryCode), true);
    }

    /**
     * Uncached registry call. Returns null on any error (network,
     * non-200, malformed body) so the caller can distinguish a failure
     * from a genuine empty list and avoid caching the failure.
     *
     * @return string[]|null
     */
    private function fetch(string $countryCode): ?array
    {
        $response = $this->apiAdapter->execute(sprintf(self::ENDPOINT, $countryCode), [], 'GET');

        // Adapter::execute always returns an array; a failure is
        // signalled by an error_code / http_status marker (never present
        // on a real registry answer).
        if (!is_array($response)
            || isset($response['error_code'])
            || isset($response['http_status'])
            || !isset($response['supported_company_types'])
            || !is_array($response['supported_company_types'])
        ) {
            $this->logRepository->addDebugLog(
                'SupportedCompanyTypes: registry fetch failed for ' . $countryCode . ', failing soft to none',
                is_array($response) ? $response : ['response' => $response]
            );
            return null;
        }

        return array_values(array_filter($response['supported_company_types'], 'is_string'));
    }
}
