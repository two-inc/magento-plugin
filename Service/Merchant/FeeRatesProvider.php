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
 * The merchant's fee per payment term, with the last retrieved set kept as a
 * last-known-good value.
 *
 * An empty fee reads as "this term carries no fee", so a failed fetch may not
 * answer with nothing: the cached set is served instead and the caller is told
 * it is not current, so the admin screen can say so (ABN-512). The entry does
 * not expire — only a successful fetch replaces it.
 *
 * Keyed on mode + API key + buyer country + the requested terms, since every
 * one of those changes the answer. The cached set is pre-FX, in the merchant's
 * own contractual currency, so it is valid for any scope the grid renders in.
 */
class FeeRatesProvider
{
    public const ENDPOINT = '/pricing/v1/merchant/rates';

    private const CACHE_KEY_PREFIX = 'two_gateway_merchant_fee_rates_';

    private const FAILURE_COOLDOWN_SUFFIX = '_cooldown';

    /** Seconds before a failed fetch is retried, so an outage is not a fetch per render. */
    private const FAILURE_COOLDOWN = 60;

    /** The screen renders per admin page load, so a fetch may not outlast a page. */
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
     * Fees per term in the merchant's own contractual currency, fresh if the
     * call succeeded and otherwise the last set retrieved for this identity.
     *
     * `stale` says which; `fetched_at` is when the returned set was retrieved.
     * A false `success` means there is nothing to show at all.
     *
     * @param int[] $terms
     * @return array{success: bool, currency?: string, fees?: array<string, array{percentage: float, fixed: float}>, stale?: bool, fetched_at?: int, error?: string}
     */
    public function getRates(array $terms, string $buyerCountry, ?int $storeId = null): array
    {
        $cacheKey = $this->cacheKey($terms, $buyerCountry, $storeId);
        $cooling = $cacheKey !== null
            && $this->cache->load($cacheKey . self::FAILURE_COOLDOWN_SUFFIX) !== false;

        $normalised = $cooling
            ? ['success' => false, 'error' => 'upstream']
            : $this->fetch($terms, $buyerCountry, $storeId);

        if ($normalised['success']) {
            $normalised['fetched_at'] = time();
            $normalised['stale'] = false;
            if ($cacheKey !== null) {
                $this->cache->save($this->json->serialize($normalised), $cacheKey, self::CACHE_TAGS, null);
                $this->cache->remove($cacheKey . self::FAILURE_COOLDOWN_SUFFIX);
            }
            return $normalised;
        }

        if ($cacheKey !== null && !$cooling) {
            $this->cache->save(
                '1',
                $cacheKey . self::FAILURE_COOLDOWN_SUFFIX,
                self::CACHE_TAGS,
                self::FAILURE_COOLDOWN
            );
        }

        $cached = $cacheKey === null ? null : $this->loadRates($cacheKey);
        if ($cached === null) {
            return $normalised;
        }
        $cached['stale'] = true;

        return $cached;
    }

    /**
     * One live call, normalised. A throw counts as a failed fetch: the adapter
     * raises rather than answering when a 200 carries a body it cannot decode,
     * which is exactly what an interception page in front of the API produces.
     *
     * @param int[] $terms
     * @return array{success: bool, currency?: string, fees?: array<string, array{percentage: float, fixed: float}>, error?: string}
     */
    private function fetch(array $terms, string $buyerCountry, ?int $storeId): array
    {
        try {
            $response = $this->apiAdapter->execute(
                self::ENDPOINT,
                [
                    'buyer_country_code' => $buyerCountry,
                    // TODO: no admin recourse-pricing config exists yet.
                    'recourse_pricing' => false,
                    // payout_schedule intentionally omitted: no admin override exists yet.
                    'net_terms' => array_values($terms),
                ],
                'POST',
                $storeId,
                null,
                null,
                self::FETCH_TIMEOUT_SECONDS
            );
        } catch (\Throwable $e) {
            $this->logRepository->addErrorLog(
                'FeeRatesProvider: fee rates fetch failed',
                ['error' => $e->getMessage()]
            );
            return ['success' => false, 'error' => 'upstream'];
        }

        return $this->normalise($response);
    }

    /**
     * @return array{success: bool, currency?: string, fees?: array<string, array{percentage: float, fixed: float}>, fetched_at?: int}|null
     */
    private function loadRates(string $cacheKey): ?array
    {
        $cached = $this->cache->load($cacheKey);
        if ($cached === false) {
            return null;
        }
        try {
            $rates = $this->json->unserialize($cached);
        } catch (\InvalidArgumentException $e) {
            return null;
        }

        return is_array($rates) && !empty($rates['success']) && !empty($rates['fees']) ? $rates : null;
    }

    /**
     * Null when no API key is stored: there is no identity to cache against,
     * and nothing to fetch either.
     *
     * @param int[] $terms
     */
    private function cacheKey(array $terms, string $buyerCountry, ?int $storeId): ?string
    {
        $apiKey = (string)$this->configRepository->getApiKey($storeId);
        if ($apiKey === '') {
            return null;
        }
        $terms = array_map('intval', $terms);
        sort($terms);

        return self::CACHE_KEY_PREFIX . hash(
            'sha256',
            $this->configRepository->getMode($storeId)
            . "\0" . $apiKey
            . "\0" . $buyerCountry
            . "\0" . implode(',', $terms)
        );
    }

    /**
     * Flattens the rates response into the shape the grid JS consumes.
     * Handles the Adapter's failure envelope too.
     *
     * @param array<string,mixed> $response
     * @return array{success: bool, currency?: string, fees?: array<string, array{percentage: float, fixed: float}>, error?: string}
     */
    private function normalise(array $response): array
    {
        if (isset($response['error_code']) || !isset($response['rates'])) {
            return ['success' => false, 'error' => 'upstream'];
        }

        $fees = [];
        foreach ((array)$response['rates'] as $rate) {
            if (!isset($rate['net_terms'])) {
                continue;
            }
            $days = (int)$rate['net_terms'];
            $fees[(string)$days] = [
                // API sends strings — cast for JSON numeric output.
                'percentage' => (float)($rate['percentage_fee'] ?? 0),
                'fixed' => (float)($rate['fixed_fee'] ?? 0),
            ];
        }

        if ($fees === []) {
            // Nothing priced is not an answer: caching it would overwrite the
            // last-known-good set with a set the screen cannot render.
            return ['success' => false, 'error' => 'upstream'];
        }

        return [
            'success' => true,
            'currency' => (string)($response['currency'] ?? ''),
            'fees' => $fees,
        ];
    }
}
