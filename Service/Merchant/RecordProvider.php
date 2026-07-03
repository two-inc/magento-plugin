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
 * Resolves the merchant record from GET /v1/merchant/{id} and caches it.
 *
 * The merchant endpoint is the single source of truth for the
 * commercial values the plugin used to carry in brand.xml — the
 * offerable payment terms, the buyer-surcharge cap, the minimum order
 * value and the merchant's default term. This provider owns the
 * fetch-and-cache protocol once, so every consumer (min-order gate,
 * admin surcharge/terms config, checkout default term) reads a single
 * cached record rather than re-implementing verify → fetch → cache.
 *
 * The API key authenticates but does not name the merchant;
 * verify_api_key resolves the id the merchant endpoint needs. The
 * resolved record is memoized per request and cached for
 * CACHE_LIFETIME seconds, keyed on the API key so a key swap
 * (different merchant, or sandbox <-> production) never serves the old
 * merchant's record. A fetch failure resolves to null and is cached as
 * such: callers degrade to their own "no value configured" behaviour
 * rather than paying two API calls per page view on a blip.
 */
class RecordProvider
{
    private const CACHE_KEY_PREFIX = 'two_gateway_merchant_record_';
    private const CACHE_LIFETIME = 900;

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
        $apiKey = (string)$this->configRepository->getApiKey($storeId);
        if ($apiKey === '') {
            return null;
        }
        // Key on the API key so a key swap (different merchant, or
        // sandbox <-> production) never serves the old merchant's record.
        $cacheKey = self::CACHE_KEY_PREFIX . hash('sha256', $apiKey);

        if (isset($this->memo[$cacheKey])) {
            return $this->memo[$cacheKey]['record'];
        }

        $cached = $this->cache->load($cacheKey);
        if ($cached !== false) {
            $wrapper = $this->json->unserialize($cached);
            $this->memo[$cacheKey] = $wrapper;
            return $wrapper['record'];
        }

        $record = $this->fetchRecord($storeId);

        $wrapper = ['record' => $record];
        $this->memo[$cacheKey] = $wrapper;
        $this->cache->save($this->json->serialize($wrapper), $cacheKey, [], self::CACHE_LIFETIME);

        return $record;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function fetchRecord(?int $storeId): ?array
    {
        // The API key authenticates but does not name the merchant;
        // verify_api_key resolves the id the merchant endpoint needs.
        $verify = $this->apiAdapter->execute('/v1/merchant/verify_api_key', [], 'GET', $storeId);
        $merchantId = $verify['id'] ?? null;
        if (!is_string($merchantId) || $merchantId === '') {
            $this->logRepository->addDebugLog(
                'RecordProvider: could not resolve merchant id, treating as no record',
                $verify
            );
            return null;
        }

        $merchant = $this->apiAdapter->execute('/v1/merchant/' . $merchantId, [], 'GET', $storeId);

        return is_array($merchant) ? $merchant : null;
    }
}
