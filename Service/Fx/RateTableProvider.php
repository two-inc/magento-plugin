<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Service\Fx;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Two\Gateway\Api\Config\RepositoryInterface as ConfigRepository;
use Two\Gateway\Api\Log\RepositoryInterface as LogRepository;
use Two\Gateway\Service\Api\Adapter;

/**
 * Fetches and caches Two's EUR-pivot FX spot-rate table from
 * GET /refdata/v1/fx-rates.
 *
 * The endpoint returns the full table in one call — `rates[CCY]` is the
 * value of one unit of CCY in EUR — plus an `as_of` staleness floor, so a
 * single fetch covers every currency a checkout can present; there is no
 * per-currency refresh list to maintain. The call is authenticated with the
 * merchant's API key and made server-side only (never from browser JS).
 *
 * Cache protocol (last-known-good):
 * - A fetched table is written to the cross-request cache with NO expiry, so
 *   it is never evicted by age alone and survives a refresh outage — gate
 *   conversions (minimum order) are specified to use last-known-good and
 *   fail closed only when no table has EVER been fetched. Note this is
 *   "no TTL-based eviction", not an unconditional guarantee: a `cache:flush`
 *   (part of this repo's standard deploy workflow — see AGENTS.md) clears
 *   the entry like any other cache data, and the very next lookup then
 *   fetches synchronously and falls closed if that fetch also fails.
 * - A table older than REFRESH_INTERVAL (6h) is refreshed in the background
 *   by cron ({@see \Two\Gateway\Cron\RefreshFxRates}); the read path also
 *   refreshes opportunistically when it sees a stale or missing table, so a
 *   dead cron degrades freshness, never availability.
 * - A failed fetch never overwrites the cached table; the stale table keeps
 *   serving. A short failure cooldown stops the hot path (the payment
 *   method's isAvailable()) from re-attempting the fetch on every call
 *   while the API is unreachable.
 * - The cache key includes the API-key hash so a key swap (different
 *   merchant, or sandbox <-> production) never serves rates fetched under
 *   the old key's mode.
 */
class RateTableProvider
{
    public const ENDPOINT = '/refdata/v1/fx-rates';

    /** Age (seconds) beyond which the cached table is refreshed: 6 hours. */
    public const REFRESH_INTERVAL = 21600;

    private const CACHE_KEY_PREFIX = 'two_gateway_fx_rate_table_';
    private const FAILURE_COOLDOWN_SUFFIX = '_cooldown';

    /** Seconds between fetch attempts after a failure. */
    private const FAILURE_COOLDOWN = 300;

    /**
     * @var Adapter
     */
    private $apiAdapter;

    /**
     * @var ConfigRepository
     */
    private $configRepository;

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
     * Per-request memo, keyed like the cache. Holds ['table' => ?array]
     * wrappers so a resolved "no table" is distinguishable from "not yet
     * resolved" — rate lookups sit on the isAvailable() hot path and must
     * cost at most one cache read (or fetch) per request.
     *
     * @var array<string,array{table: ?array}>
     */
    private $memo = [];

    public function __construct(
        Adapter $apiAdapter,
        ConfigRepository $configRepository,
        CacheInterface $cache,
        Json $json,
        LogRepository $logRepository
    ) {
        $this->apiAdapter = $apiAdapter;
        $this->configRepository = $configRepository;
        $this->cache = $cache;
        $this->json = $json;
        $this->logRepository = $logRepository;
    }

    /**
     * The current FX rate table, refreshed opportunistically when stale.
     *
     * Returns the freshest table available — a stale table is still
     * returned when a refresh attempt fails (last-known-good). Returns
     * null only when no table has ever been fetched under the current
     * API key and one cannot be fetched now.
     *
     * @return array{rates: array<string,float>, as_of: ?string, fetched_at: int}|null
     */
    public function getRateTable(?int $storeId = null): ?array
    {
        $cacheKey = $this->cacheKey($storeId);
        if ($cacheKey === null) {
            return null;
        }

        if (isset($this->memo[$cacheKey])) {
            return $this->memo[$cacheKey]['table'];
        }

        $entry = $this->loadEntry($cacheKey);

        $age = $entry === null ? null : time() - (int)$entry['fetched_at'];
        if ($entry !== null && $age < self::REFRESH_INTERVAL) {
            $this->memo[$cacheKey] = ['table' => $entry];
            return $entry;
        }

        // Missing or stale. Attempt a fetch unless a recent one failed —
        // the cooldown keeps an API outage from adding a fetch round-trip
        // to every page view.
        if ($this->cache->load($cacheKey . self::FAILURE_COOLDOWN_SUFFIX) === false) {
            $fresh = $this->fetchTable($storeId);
            if ($fresh !== null) {
                $this->persist($cacheKey, $fresh);
                return $fresh;
            }
            $this->cache->save('1', $cacheKey . self::FAILURE_COOLDOWN_SUFFIX, [], self::FAILURE_COOLDOWN);
            if ($entry !== null) {
                $this->logRepository->addDebugLog(
                    'RateTableProvider: refresh failed, serving last-known-good table',
                    ['as_of' => $entry['as_of'] ?? null, 'age_seconds' => $age]
                );
            }
        }

        // Last-known-good stale table, or null when never fetched.
        $this->memo[$cacheKey] = ['table' => $entry];
        return $entry;
    }

    /**
     * Force-refresh the cached table (cron entry point). A failed fetch
     * leaves the existing cached table untouched.
     *
     * @return bool whether a fresh table was fetched and cached
     */
    public function refresh(?int $storeId = null): bool
    {
        $cacheKey = $this->cacheKey($storeId);
        if ($cacheKey === null) {
            return false;
        }

        $fresh = $this->fetchTable($storeId);
        if ($fresh === null) {
            $this->logRepository->addErrorLog(
                'RateTableProvider: background FX rate refresh failed, keeping last-known-good table',
                ['store_id' => $storeId]
            );
            return false;
        }

        $this->persist($cacheKey, $fresh);
        return true;
    }

    /**
     * The cached entry, or null when absent or unusable. A corrupt or
     * wrong-shaped cache value must degrade to "missing" (refetch), never
     * throw — this sits on the payment method's isAvailable() hot path.
     *
     * @return array{rates: array<string,float>, as_of: ?string, fetched_at: int}|null
     */
    private function loadEntry(string $cacheKey): ?array
    {
        $cached = $this->cache->load($cacheKey);
        if ($cached === false) {
            return null;
        }
        try {
            $entry = $this->json->unserialize($cached);
        } catch (\InvalidArgumentException $e) {
            $this->logRepository->addDebugLog(
                'RateTableProvider: discarding corrupt cached rate table',
                ['error' => $e->getMessage()]
            );
            return null;
        }
        if (!is_array($entry)
            || !isset($entry['fetched_at'])
            || !isset($entry['rates'])
            || !is_array($entry['rates'])
            || $entry['rates'] === []
        ) {
            $this->logRepository->addDebugLog(
                'RateTableProvider: discarding malformed cached rate table',
                ['entry' => $entry]
            );
            return null;
        }
        return $entry;
    }

    /**
     * The cache key for the current API key, or null when no key is
     * configured (nothing to authenticate the fetch with).
     */
    private function cacheKey(?int $storeId): ?string
    {
        $apiKey = (string)$this->configRepository->getApiKey($storeId);
        if ($apiKey === '') {
            return null;
        }
        return self::CACHE_KEY_PREFIX . hash('sha256', $apiKey);
    }

    /**
     * @param array{rates: array<string,float>, as_of: ?string, fetched_at: int} $entry
     */
    private function persist(string $cacheKey, array $entry): void
    {
        // No expiry (null lifetime): the table is the last-known-good source
        // for gate conversions and must outlive any refresh outage. Staleness
        // is tracked via fetched_at, not cache eviction.
        $this->cache->save($this->json->serialize($entry), $cacheKey, [], null);
        $this->memo[$cacheKey] = ['table' => $entry];
    }

    /**
     * @return array{rates: array<string,float>, as_of: ?string, fetched_at: int}|null
     */
    private function fetchTable(?int $storeId): ?array
    {
        $response = $this->apiAdapter->execute(self::ENDPOINT, [], 'GET', $storeId);

        // Adapter::execute always returns an array; a failure is signalled
        // by an error_code / http_status marker (never present on a real
        // rates payload). Treat those — and a payload without a usable
        // rates map — as a failed fetch.
        if (!is_array($response)
            || isset($response['error_code'])
            || isset($response['http_status'])
            || !isset($response['rates'])
            || !is_array($response['rates'])
        ) {
            $this->logRepository->addDebugLog(
                'RateTableProvider: FX rates fetch failed',
                is_array($response) ? $response : ['response' => $response]
            );
            return null;
        }

        $rates = [];
        foreach ($response['rates'] as $currency => $value) {
            $rate = (float)$value;
            if (is_string($currency) && $currency !== '' && $rate > 0) {
                $rates[strtoupper($currency)] = $rate;
            }
        }
        if ($rates === []) {
            $this->logRepository->addDebugLog('RateTableProvider: FX rates payload contained no usable rates', $response);
            return null;
        }

        return [
            'rates' => $rates,
            'as_of' => isset($response['as_of']) ? (string)$response['as_of'] : null,
            'fetched_at' => time(),
        ];
    }
}
