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
use Two\Gateway\Service\Api\Adapter;

/**
 * Cached, categorised outcome of GET /v1/merchant/verify_api_key for the
 * currently-stored API key.
 *
 * Two jobs, both of which used to be done ad-hoc by each caller:
 *
 * 1. CATEGORISE. Every non-200 outcome used to be reported identically
 *    ("API key is not valid"), so an admin whose store simply could not
 *    reach the API was told their key was wrong and went looking for the
 *    wrong fix. getStatus() sorts the outcome into buckets an admin can
 *    act on: OK, INVALID_KEY (401/403 — the key really is wrong),
 *    SERVICE_ERROR (5xx — the key may be fine, the service is not),
 *    UNREACHABLE (no HTTP exchange completed at all: DNS, TLS, routing,
 *    timeout), ERROR (some other non-2xx status), MALFORMED_RESPONSE (a
 *    2xx that did not carry a merchant id) and NOT_CONFIGURED (no key
 *    saved yet).
 *
 * 2. CACHE. The verification result gates checkout — the payment method's
 *    availability and the checkout config subtree that the company-search
 *    control is mounted behind — and both of those are evaluated on every
 *    cart/checkout render. A live HTTP round-trip per render is not
 *    acceptable, so results are memoised per request and cached across
 *    requests, following the same protocol as the sibling providers in
 *    this namespace ({@see RecordProvider}) and Service\Fx\RateTableProvider.
 *
 * Cache protocol:
 * - Keyed on a hash of the API key AND the resolved mode, so correcting
 *   the key, pointing it at a different merchant, or switching
 *   sandbox <-> production all miss the cache immediately and re-verify
 *   rather than serving the previous verdict. The mode belongs in the key
 *   because it decides which host the key is verified against, and two
 *   store views can share a key while being configured to different modes.
 * - A success is cached for CACHE_LIFETIME (5 min). Short enough that a
 *   key which is revoked upstream stops being honoured within minutes,
 *   long enough that checkout renders cost nothing.
 * - A failure is cached for FAILURE_CACHE_LIFETIME (1 min) rather than
 *   not at all: while the API is unreachable, every checkout render would
 *   otherwise add its own timing-out HTTP call to the page. Same reasoning
 *   as RateTableProvider's failure cooldown, with a short TTL so a
 *   recovery (or a corrected key) is picked up quickly.
 * - refresh() bypasses the cache for a live check and writes the result
 *   forward into it. The admin settings page uses that, so a merchant who
 *   has just corrected a broken key does not have to wait out the TTL for
 *   checkout to notice.
 *
 * The raw response body is deliberately NOT part of the returned status
 * for any failure category — only the bucket and the HTTP status code.
 * Verification failures are surfaced to a merchant in the admin UI and an
 * upstream error body is not something to render there.
 */
class ApiKeyStatus
{
    /** The key verified: an HTTP 200 carrying a merchant id. */
    public const OK = 'ok';

    /** HTTP 401/403 — the key was rejected. Invalid, expired, or wrong environment. */
    public const INVALID_KEY = 'invalid_key';

    /** HTTP 5xx — the service failed. The key itself may well be fine. */
    public const SERVICE_ERROR = 'service_error';

    /** No HTTP exchange completed: DNS, TLS, routing, connection, or timeout. */
    public const UNREACHABLE = 'unreachable';

    /** Some other non-2xx status. */
    public const ERROR = 'error';

    /** A 2xx response that did not carry a merchant id. */
    public const MALFORMED_RESPONSE = 'malformed_response';

    /** No API key saved in configuration — nothing to verify. */
    public const NOT_CONFIGURED = 'not_configured';

    public const ENDPOINT = '/v1/merchant/verify_api_key';

    private const CACHE_KEY_PREFIX = 'two_gateway_api_key_status_';

    /** Seconds a successful verification is served from cache. */
    private const CACHE_LIFETIME = 300;

    /**
     * Seconds a failed verification is served from cache. Deliberately
     * much shorter than CACHE_LIFETIME: long enough to stop an outage
     * putting a live, timing-out call on every checkout render, short
     * enough that a recovery surfaces quickly.
     */
    private const FAILURE_CACHE_LIFETIME = 60;

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
     * Per-request memo, keyed like the cache. Holds the status array
     * directly — unlike the nullable-value providers in this namespace
     * there is no "resolved to null" state to distinguish, since a status
     * is always a populated array.
     *
     * @var array<string,array{status: string, code: int|null, merchant: array<string,mixed>|null}>
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
     * The cached verification status for the stored API key, verifying
     * live only on a cache miss.
     *
     * @return array{status: string, code: int|null, merchant: array<string,mixed>|null}
     */
    public function getStatus(?int $storeId = null): array
    {
        $apiKey = (string)$this->configRepository->getApiKey($storeId);
        if ($apiKey === '') {
            return self::notConfigured();
        }

        $cacheKey = $this->cacheKey($apiKey, $storeId);
        if (isset($this->memo[$cacheKey])) {
            return $this->memo[$cacheKey];
        }

        $cached = $this->cache->load($cacheKey);
        if ($cached !== false) {
            // Json::unserialize throws on anything that is not valid JSON.
            // This read sits on the payment method's isAvailable() path, so
            // an unreadable cache entry has to degrade to "verify again",
            // never to an exception out of a checkout render.
            try {
                $status = $this->json->unserialize($cached);
            } catch (\InvalidArgumentException $e) {
                $status = null;
            }
            if (is_array($status) && isset($status['status'])) {
                $this->memo[$cacheKey] = $status;
                return $status;
            }
        }

        return $this->verify($apiKey, $cacheKey, $storeId);
    }

    /**
     * A live verification that ignores any cached verdict, and writes its
     * own result forward into the cache.
     *
     * For the admin settings page: an admin looking at that page wants the
     * current truth about the key in front of them, not a verdict up to
     * CACHE_LIFETIME old. Writing the fresh result forward is what lets a
     * just-corrected key take effect at checkout immediately.
     *
     * @return array{status: string, code: int|null, merchant: array<string,mixed>|null}
     */
    public function refresh(?int $storeId = null): array
    {
        $apiKey = (string)$this->configRepository->getApiKey($storeId);
        if ($apiKey === '') {
            return self::notConfigured();
        }

        return $this->verify($apiKey, $this->cacheKey($apiKey, $storeId), $storeId);
    }

    /**
     * True only when the stored API key currently verifies. Every failure
     * category is false: a buyer must not be offered a payment method
     * whose integration cannot be confirmed to work, whatever the reason
     * it cannot be confirmed.
     */
    public function isVerified(?int $storeId = null): bool
    {
        return $this->getStatus($storeId)['status'] === self::OK;
    }

    /**
     * Sorts an Adapter::execute() result into one of the status buckets.
     *
     * Reads the markers Adapter::execute() already uses to signal failure,
     * so no second HTTP path is needed to tell the categories apart:
     * - `http_status` present  => an HTTP exchange completed with a non-2xx
     *   status, and that status is the thing to categorise on.
     * - `error_code` present without `http_status` => execute() never got a
     *   status back at all. That is its catch-all for a thrown transport
     *   failure (connection refused, DNS, TLS, timeout) or a translator
     *   failure, hence UNREACHABLE.
     * - neither marker => a 2xx whose decoded body is the merchant record.
     *   verify_api_key answers with the merchant `id`; a 2xx without one is
     *   not a verification we can rely on, so it is MALFORMED_RESPONSE
     *   rather than a silent OK with no merchant.
     *
     * @param array<string,mixed> $response
     * @return array{status: string, code: int|null, merchant: array<string,mixed>|null}
     */
    public static function categorize(array $response): array
    {
        if (isset($response['http_status'])) {
            $code = (int)$response['http_status'];
            if ($code === 401 || $code === 403) {
                return ['status' => self::INVALID_KEY, 'code' => $code, 'merchant' => null];
            }
            if ($code >= 500) {
                return ['status' => self::SERVICE_ERROR, 'code' => $code, 'merchant' => null];
            }
            return ['status' => self::ERROR, 'code' => $code, 'merchant' => null];
        }

        if (isset($response['error_code'])) {
            return ['status' => self::UNREACHABLE, 'code' => null, 'merchant' => null];
        }

        $id = $response['id'] ?? null;
        if (!is_string($id) || $id === '') {
            return ['status' => self::MALFORMED_RESPONSE, 'code' => null, 'merchant' => null];
        }

        return ['status' => self::OK, 'code' => 200, 'merchant' => $response];
    }

    /**
     * Performs the live call, categorises it, caches it and memoises it.
     *
     * @return array{status: string, code: int|null, merchant: array<string,mixed>|null}
     */
    private function verify(string $apiKey, string $cacheKey, ?int $storeId): array
    {
        $status = self::categorize($this->apiAdapter->execute(self::ENDPOINT, [], 'GET', $storeId));

        if ($status['status'] !== self::OK) {
            // Log the bucket and status code only — never the response
            // body, which is what the admin-facing surfaces used to leak.
            $this->logRepository->addDebugLog(
                'ApiKeyStatus: API key verification failed',
                ['status' => $status['status'], 'http_status' => $status['code']]
            );
        }

        $lifetime = $status['status'] === self::OK ? self::CACHE_LIFETIME : self::FAILURE_CACHE_LIFETIME;
        $this->cache->save($this->json->serialize($status), $cacheKey, [], $lifetime);
        $this->memo[$cacheKey] = $status;

        return $status;
    }

    /**
     * Keyed on the API key so a key swap never serves the previous key's
     * verdict, AND on the resolved mode, because the mode decides which
     * host the key is verified against: the same key can be accepted in
     * one environment and rejected in the other, and two store views
     * sharing a key while configured to different modes would otherwise
     * share one cache slot and serve each other's verdict.
     *
     * sha256 of the key, never the key itself — cache identifiers end up
     * in log lines and cache-backend keyspaces.
     */
    private function cacheKey(string $apiKey, ?int $storeId): string
    {
        return self::CACHE_KEY_PREFIX
            . hash('sha256', $this->configRepository->getMode($storeId) . "\0" . $apiKey);
    }

    /**
     * @return array{status: string, code: int|null, merchant: array<string,mixed>|null}
     */
    private static function notConfigured(): array
    {
        return ['status' => self::NOT_CONFIGURED, 'code' => null, 'merchant' => null];
    }
}
