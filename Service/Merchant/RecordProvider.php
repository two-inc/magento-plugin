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
 * Resolves the merchant record from GET /v1/merchant/{id} and caches it as
 * a last-known-good value.
 *
 * Single read path for the commercial values the plugin used to carry in
 * brand.xml — offerable terms, buyer-surcharge cap, minimum order value,
 * default term — so no consumer re-implements verify -> fetch -> cache.
 *
 * Cached against mode + API key, since neither a key swap nor an
 * environment switch may serve the previous merchant's record.
 *
 * The entry has no expiry: only the scheduled hourly refresh replaces it, so
 * a read that finds no record at all is a fresh install or a cache flush, and
 * is logged (ABN-519).
 *
 * Freshness is the stored success stamp. The cron refreshes a record once it
 * is MAX_AGE old; one that reaches STALE_AFTER says the cron is not running,
 * so a read stands in for it — see refreshIfStale().
 *
 * A failure is never cached as the record and never moves the stamp —
 * callers degrade to their own "no value configured" behaviour only while
 * there is no record at all.
 */
class RecordProvider
{
    /** Age at which a read concludes the cron is not running and refreshes the record itself. */
    public const STALE_AFTER = 93600;

    /** Age at which the hourly cron refreshes the record. */
    public const MAX_AGE = 86400;

    /** Must match the two_gateway_refresh_merchant_record schedule in etc/crontab.xml. */
    public const CRON_INTERVAL = 3600;

    private const CACHE_KEY_PREFIX = 'two_gateway_merchant_record_';

    private const STAMP_SUFFIX = '_fetched_at';

    private const ABSENT_SUFFIX = '_absent_on_read';

    private const STOOD_IN_SUFFIX = '_stood_in_at';

    private const SCHEDULED_SUFFIX = '_scheduled_at';

    private const FAILURE_COOLDOWN_SUFFIX = '_cooldown';

    private const STALE_COOLDOWN_SUFFIX = '_stale_cooldown';

    /** Seconds before a failed fetch is retried, so an outage is not a fetch per read. */
    private const FAILURE_COOLDOWN = 60;

    /** Seconds between stand-in refreshes of a stale record: one per run the cron owes. */
    private const STALE_REFRESH_COOLDOWN = self::CRON_INTERVAL;

    /** Per call, and a stand-in makes two, so a read can lose at most four seconds to a dead cron. */
    private const STALE_FETCH_TIMEOUT_SECONDS = 2;

    /**
     * Per-call ceiling on the two GETs below. The callers that bound their own
     * wall clock — a config save, the admin button, a storefront render — can
     * only do so if an in-flight call cannot outlast their budget.
     */
    private const FETCH_TIMEOUT_SECONDS = 10;

    /** Own cache type, so `cache:clean two_gateway` drops it and a config clean does not. */
    private const CACHE_TAGS = [TwoGateway::CACHE_TAG];

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
     * id, or a fetch failure with nothing cached).
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
            return $this->refreshIfStale($cacheKey, $mode, $apiKey, $storeId, $cached) ?? $cached;
        }

        if ($this->cache->load($cacheKey . self::FAILURE_COOLDOWN_SUFFIX) !== false) {
            $this->memo[$cacheKey] = ['record' => null];
            return null;
        }

        // The entry never expires, so nothing has ever fetched one for this
        // identity, or the cache has been flushed.
        $this->logRepository->addErrorLog(
            'RecordProvider: merchant record absent on read',
            ['store_id' => $storeId]
        );

        // Armed before the fetch so concurrent renders during an outage share one attempt;
        // read path only — a button press must not push readers to null.
        $this->cache->save('1', $cacheKey . self::FAILURE_COOLDOWN_SUFFIX, self::CACHE_TAGS, self::FAILURE_COOLDOWN);
        $record = $this->fetchAndStore($cacheKey, $mode, $apiKey, $storeId, null);
        if ($record !== null) {
            $this->cache->remove($cacheKey . self::FAILURE_COOLDOWN_SUFFIX);

            return $record;
        }

        // Marked only once the read could not resolve one either: no record and
        // no way to get one is what the admin health surface has to report. The
        // FIRST such read owns the timestamp — rewriting it on every later one
        // keeps the mark permanently young, and the health surface judges its age.
        if ($this->cache->load($cacheKey . self::ABSENT_SUFFIX) === false) {
            $this->cache->save((string)time(), $cacheKey . self::ABSENT_SUFFIX, self::CACHE_TAGS, null);
        }

        return null;
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
     * Whether the cron should refresh this identity: no record, no stamp, or
     * a stamp MAX_AGE old. A stamp whose record is gone is due, not fresh.
     */
    public function isDue(string $mode, string $apiKey): bool
    {
        $cacheKey = $this->cacheKey($mode, $apiKey);
        if ($cacheKey === null) {
            return false;
        }
        if ($this->loadRecord($cacheKey) === null) {
            return true;
        }
        $fetchedAt = $this->status($mode, $apiKey)['fetched_at'];

        return $fetchedAt === null || time() - $fetchedAt >= self::MAX_AGE;
    }

    /** The scheduled refresh has run for this identity, so neither mark a read left is a signal any more. */
    public function noteScheduledRun(string $mode, string $apiKey): void
    {
        $cacheKey = $this->cacheKey($mode, $apiKey);
        if ($cacheKey !== null) {
            $this->cache->remove($cacheKey . self::ABSENT_SUFFIX);
            $this->cache->remove($cacheKey . self::STOOD_IN_SUFFIX);
            // Recorded whether or not the run's own fetch succeeded: a cron that
            // runs and cannot reach the API is not a cron that is not running.
            $this->cache->save((string)time(), $cacheKey . self::SCHEDULED_SUFFIX, self::CACHE_TAGS, null);
        }
    }

    /**
     * The Diagnostics panel's view of the refresh: when the record was last
     * fetched successfully, when a read last found it unresolvable, when a read
     * last had to stand in for the cron, and when the cron last ran. The two
     * read marks are cleared by a scheduled run, and the run stamp moves even
     * when the run's own fetch fails — so a cron that runs against a dead API
     * is never mistaken for a cron that is not running.
     *
     * @return array{
     *     fetched_at: int|null,
     *     absent_on_read_at: int|null,
     *     stood_in_at: int|null,
     *     scheduled_at: int|null
     * }
     */
    public function status(string $mode, string $apiKey): array
    {
        $cacheKey = $this->cacheKey($mode, $apiKey);
        if ($cacheKey === null) {
            return [
                'fetched_at' => null,
                'absent_on_read_at' => null,
                'stood_in_at' => null,
                'scheduled_at' => null,
            ];
        }

        return [
            'fetched_at' => $this->loadTimestamp($cacheKey . self::STAMP_SUFFIX),
            'absent_on_read_at' => $this->loadTimestamp($cacheKey . self::ABSENT_SUFFIX),
            'stood_in_at' => $this->loadTimestamp($cacheKey . self::STOOD_IN_SUFFIX),
            'scheduled_at' => $this->loadTimestamp($cacheKey . self::SCHEDULED_SUFFIX),
        ];
    }

    /**
     * A record at STALE_AFTER means the scheduled refresh is not running, so a
     * read stands in for it, once per run the cron owes and on a budget a page
     * render can afford. A record with no stamp is left to the cron, which
     * already counts it due (ABN-519).
     *
     * @param array<string,mixed> $held record already cached, kept on a failed fetch
     * @return array<string,mixed>|null
     */
    private function refreshIfStale(
        string $cacheKey,
        string $mode,
        string $apiKey,
        ?int $storeId,
        array $held
    ): ?array {
        $fetchedAt = $this->loadTimestamp($cacheKey . self::STAMP_SUFFIX);
        if ($fetchedAt === null || time() - $fetchedAt < self::STALE_AFTER) {
            return null;
        }
        if ($this->cache->load($cacheKey . self::STALE_COOLDOWN_SUFFIX) !== false) {
            return null;
        }
        // Armed before the fetch, as on the absent-on-read path, so concurrent
        // renders share one attempt.
        $this->cache->save(
            '1',
            $cacheKey . self::STALE_COOLDOWN_SUFFIX,
            self::CACHE_TAGS,
            self::STALE_REFRESH_COOLDOWN
        );
        // First stand-in owns the timestamp, as with the absent mark: stand-ins
        // recur every cooldown, and rewriting the clock keeps the mark young
        // enough that the health surface never acts on it.
        if ($this->cache->load($cacheKey . self::STOOD_IN_SUFFIX) === false) {
            $this->cache->save((string)time(), $cacheKey . self::STOOD_IN_SUFFIX, self::CACHE_TAGS, null);
        }

        return $this->fetchAndStore(
            $cacheKey,
            $mode,
            $apiKey,
            $storeId,
            $held,
            self::STALE_FETCH_TIMEOUT_SECONDS
        );
    }

    private function loadTimestamp(string $key): ?int
    {
        $value = $this->cache->load($key);

        return is_string($value) && ctype_digit($value) ? (int)$value : null;
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
        ?array $surviving,
        int $timeoutSeconds = self::FETCH_TIMEOUT_SECONDS
    ): ?array {
        $record = $this->fetchRecord($mode, $apiKey, $storeId, $timeoutSeconds);

        // Memoize either way so a single request never pays the
        // verify+fetch round-trip twice.
        if ($record !== null) {
            // Null lifetime: the entry never expires, so nothing but a
            // successful refresh or a manual flush can take it away.
            $this->cache->save(
                $this->json->serialize(['record' => $record]),
                $cacheKey,
                self::CACHE_TAGS,
                null
            );
            // The success clock: moves only here, never on a failure.
            $this->cache->save((string)time(), $cacheKey . self::STAMP_SUFFIX, self::CACHE_TAGS, null);
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
    private function fetchRecord(string $mode, string $apiKey, ?int $storeId, int $timeoutSeconds): ?array
    {
        // The key authenticates but does not name the merchant.
        $verify = $this->apiAdapter->execute(
            '/v1/merchant/verify_api_key',
            [],
            'GET',
            $storeId,
            $apiKey,
            $mode,
            $timeoutSeconds
        );
        $merchantId = $verify['id'] ?? null;
        if (!is_string($merchantId) || $merchantId === '') {
            $this->logRepository->addErrorLog(
                'RecordProvider: could not resolve merchant id, treating as no record',
                $verify
            );
            return null;
        }

        $merchant = $this->apiAdapter->execute(
            '/v1/merchant/' . $merchantId,
            [],
            'GET',
            $storeId,
            $apiKey,
            $mode,
            $timeoutSeconds
        );

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
