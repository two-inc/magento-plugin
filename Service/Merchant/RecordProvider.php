<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Service\Merchant;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Two\Gateway\Api\Config\RepositoryInterface as ConfigRepository;
use Two\Gateway\Api\Log\RepositoryInterface as LogRepository;
use Two\Gateway\Model\Cache\Type\TwoGateway;
use Two\Gateway\Service\Api\Adapter;

/**
 * Resolves the merchant record from GET /v1/merchant/{id} and caches it.
 *
 * Single read path for the commercial values the plugin used to carry in
 * brand.xml — offerable terms, buyer-surcharge cap, minimum order value,
 * default term — so no consumer re-implements verify -> fetch -> cache.
 *
 * Cached against mode + API key, since neither a key swap nor an
 * environment switch may serve the previous merchant's record.
 *
 * Refreshed by event — cache miss, nightly cron, API-key or environment
 * save, admin button — so prompt refresh needs a running cron; expiry is
 * the staleness ceiling on an install without one.
 *
 * A failure is never cached as the record — callers degrade to their own
 * "no value configured" behaviour while it is null.
 */
class RecordProvider
{
    private const CACHE_KEY_PREFIX = 'two_gateway_merchant_record_';
    /** Staleness ceiling where no cron runs; the events above refresh sooner. */
    private const CACHE_LIFETIME = 3600;

    /** Own cache type, so `cache:clean two_gateway` drops it and a config clean does not. */
    private const CACHE_TAGS = [TwoGateway::CACHE_TAG];

    private const FAILURE_COOLDOWN_SUFFIX = '_cooldown';

    /** Seconds before a failed fetch is retried, so an outage is not a fetch per read. */
    private const FAILURE_COOLDOWN = 60;

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
     * Per-request memo, keyed like the cache. Holds ['record' => ?array]
     * wrappers so a resolved "no record" is distinguishable from "not
     * yet resolved".
     *
     * @var array<string,array{record: ?array}>
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
     * The merchant record from GET /v1/merchant/{id}, or null when it
     * cannot currently be resolved (no API key, unresolvable merchant
     * id, or a fetch failure).
     *
     * @return array<string,mixed>|null
     */
    public function getRecord(?int $storeId = null): ?array
    {
        $mode = $this->configRepository->getMode($storeId);
        $apiKey = $this->configRepository->getApiKey($storeId);
        $cacheKey = $this->cacheKey($mode, $apiKey);
        if ($cacheKey === null) {
            return null;
        }

        if (isset($this->memo[$cacheKey])) {
            return $this->memo[$cacheKey]['record'];
        }

        $cached = $this->loadRecord($cacheKey);
        if ($cached !== null) {
            $this->memo[$cacheKey] = ['record' => $cached];
            return $cached;
        }

        if ($this->cache->load($cacheKey . self::FAILURE_COOLDOWN_SUFFIX) !== false) {
            $this->memo[$cacheKey] = ['record' => null];
            return null;
        }

        // Armed before the fetch so concurrent renders during an outage share one attempt;
        // read path only — a button press must not push readers to null.
        $this->cache->save('1', $cacheKey . self::FAILURE_COOLDOWN_SUFFIX, self::CACHE_TAGS, self::FAILURE_COOLDOWN);
        $record = $this->fetchAndStore($cacheKey, $mode, $apiKey, $storeId, null);
        if ($record !== null) {
            $this->cache->remove($cacheKey . self::FAILURE_COOLDOWN_SUFFIX);
        }

        return $record;
    }

    /**
     * A live fetch of one cache identity for a cron or an admin who asked for
     * one: ignores both the cached record and the read cooldown, arms neither,
     * and returns null on failure with any existing entry left in place.
     *
     * @param int|null $storeId a store view reading this identity, for its request headers
     * @return array<string,mixed>|null
     */
    public function refresh(string $mode, string $apiKey, ?int $storeId = null): ?array
    {
        $cacheKey = $this->cacheKey($mode, $apiKey);
        if ($cacheKey === null) {
            return null;
        }
        unset($this->memo[$cacheKey]);

        return $this->fetchAndStore($cacheKey, $mode, $apiKey, $storeId, $this->loadRecord($cacheKey));
    }

    /**
     * The cached record, or null when absent, corrupt or wrong-shaped — this
     * sits on isAvailable(), so an unreadable entry refetches, never throws.
     *
     * @return array<string,mixed>|null
     */
    private function loadRecord(string $cacheKey): ?array
    {
        $cached = $this->cache->load($cacheKey);
        if ($cached === false) {
            return null;
        }
        try {
            $wrapper = $this->json->unserialize($cached);
        } catch (\InvalidArgumentException $e) {
            $this->logRepository->addDebugLog(
                'RecordProvider: discarding corrupt cached merchant record',
                ['error' => $e->getMessage()]
            );
            return null;
        }
        if (!is_array($wrapper)
            || !isset($wrapper['record'])
            || !is_array($wrapper['record'])
            || $wrapper['record'] === []
        ) {
            return null;
        }

        return $wrapper['record'];
    }

    /**
     * @param array<string,mixed>|null $surviving record already in the cache, kept on a failed fetch
     * @return array<string,mixed>|null
     */
    private function fetchAndStore(
        string $cacheKey,
        string $mode,
        string $apiKey,
        ?int $storeId,
        ?array $surviving
    ): ?array {
        $record = $this->fetchRecord($mode, $apiKey, $storeId);

        // Memoize either way so a single request never pays the
        // verify+fetch round-trip twice.
        if ($record !== null) {
            $this->cache->save(
                $this->json->serialize(['record' => $record]),
                $cacheKey,
                self::CACHE_TAGS,
                self::CACHE_LIFETIME
            );
            $this->memo[$cacheKey] = ['record' => $record];

            return $record;
        }

        // Memoize the surviving entry, not the failure.
        $this->memo[$cacheKey] = ['record' => $surviving];

        return null;
    }

    /** Mode + API key, as in ApiKeyStatus — one key, two environments, two merchants. */
    private function cacheKey(string $mode, string $apiKey): ?string
    {
        if ($apiKey === '') {
            return null;
        }

        return self::CACHE_KEY_PREFIX . hash('sha256', $mode . "\0" . $apiKey);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function fetchRecord(string $mode, string $apiKey, ?int $storeId): ?array
    {
        // The key authenticates but does not name the merchant.
        $verify = $this->apiAdapter->execute('/v1/merchant/verify_api_key', [], 'GET', $storeId, $apiKey, $mode);
        $merchantId = $verify['id'] ?? null;
        if (!is_string($merchantId) || $merchantId === '') {
            $this->logRepository->addErrorLog(
                'RecordProvider: could not resolve merchant id, treating as no record',
                $verify
            );
            return null;
        }

        $merchant = $this->apiAdapter->execute('/v1/merchant/' . $merchantId, [], 'GET', $storeId, $apiKey, $mode);

        // Adapter failure markers, or an empty 200 body decoded to [] — neither is a record.
        if (!is_array($merchant)
            || $merchant === []
            || isset($merchant['error_code'])
            || isset($merchant['http_status'])
        ) {
            $this->logRepository->addErrorLog(
                'RecordProvider: merchant fetch failed, treating as no record',
                is_array($merchant) ? $merchant : ['response' => $merchant]
            );
            return null;
        }

        return $merchant;
    }
}
