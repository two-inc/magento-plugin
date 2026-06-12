<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Service\Order;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Two\Gateway\Api\Config\RepositoryInterface as ConfigRepository;
use Two\Gateway\Api\Log\RepositoryInterface as LogRepository;
use Two\Gateway\Service\Api\Adapter;

/**
 * Resolves the merchant's minimum order value from the Two API.
 *
 * GET /v1/merchant/{id} carries the effective minimum (funding-partner
 * default with any merchant override, resolved server-side) as
 * min_order_amount / min_order_currency / min_order_basis. That response
 * is the single source of truth: the same value checkout-api enforces at
 * order create/intent, so the storefront gate and the server can never
 * disagree on the threshold.
 *
 * isAvailable() fires many times per page view, so the resolved tuple is
 * memoized per request and cached for CACHE_LIFETIME seconds. The "no
 * minimum configured" outcome is cached too - that is the common case and
 * must not cost two API calls per page view. A fetch failure resolves to
 * null (no minimum): the server still enforces, and hiding the payment
 * method on an API blip would be the worse failure.
 */
class MinimumOrderProvider
{
    private const CACHE_KEY_PREFIX = 'two_gateway_minimum_order_';
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
     * Per-request memo, keyed like the cache. Holds ['minimum' => ?array]
     * wrappers so a resolved "no minimum" is distinguishable from "not
     * yet resolved".
     *
     * @var array<string,array{minimum: ?array}>
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
     * The merchant's effective minimum order value, or null when none is
     * configured (or it cannot currently be resolved).
     *
     * @return array{amount: float, currency: string, basis: string}|null
     */
    public function getMinimum(?int $storeId = null): ?array
    {
        $apiKey = (string)$this->configRepository->getApiKey($storeId);
        if ($apiKey === '') {
            return null;
        }
        // Key on the API key so a key swap (different merchant, or
        // sandbox <-> production) never serves the old merchant's minimum.
        $cacheKey = self::CACHE_KEY_PREFIX . hash('sha256', $apiKey);

        if (isset($this->memo[$cacheKey])) {
            return $this->memo[$cacheKey]['minimum'];
        }

        $cached = $this->cache->load($cacheKey);
        if ($cached !== false) {
            $wrapper = $this->json->unserialize($cached);
            $this->memo[$cacheKey] = $wrapper;
            return $wrapper['minimum'];
        }

        $minimum = $this->fetchMinimum($storeId);

        $wrapper = ['minimum' => $minimum];
        $this->memo[$cacheKey] = $wrapper;
        $this->cache->save($this->json->serialize($wrapper), $cacheKey, [], self::CACHE_LIFETIME);

        return $minimum;
    }

    /**
     * @return array{amount: float, currency: string, basis: string}|null
     */
    private function fetchMinimum(?int $storeId): ?array
    {
        // The API key authenticates but does not name the merchant;
        // verify_api_key resolves the id the merchant endpoint needs.
        $verify = $this->apiAdapter->execute('/v1/merchant/verify_api_key', [], 'GET', $storeId);
        $merchantId = $verify['id'] ?? null;
        if (!is_string($merchantId) || $merchantId === '') {
            $this->logRepository->addDebugLog(
                'MinimumOrderProvider: could not resolve merchant id, treating as no minimum',
                $verify
            );
            return null;
        }

        $merchant = $this->apiAdapter->execute('/v1/merchant/' . $merchantId, [], 'GET', $storeId);

        $amount = $merchant['min_order_amount'] ?? null;
        $currency = $merchant['min_order_currency'] ?? null;
        $basis = $merchant['min_order_basis'] ?? null;
        // The API omits all three fields when no minimum is configured; a
        // partial or malformed tuple is treated the same way rather than
        // gating on a guess.
        if (!is_numeric($amount)
            || (float)$amount <= 0
            || !is_string($currency)
            || $currency === ''
            || !in_array($basis, ['net', 'gross'], true)
        ) {
            return null;
        }

        return [
            'amount' => (float)$amount,
            'currency' => strtoupper($currency),
            'basis' => $basis,
        ];
    }
}
