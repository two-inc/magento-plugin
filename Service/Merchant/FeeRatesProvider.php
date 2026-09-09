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
 * The merchant's fee per payment term, as a last-known-good value.
 *
 * The admin payment-terms screen renders a fee beside every term. Those
 * figures used to be fetched live per page render with no cached copy, so an
 * upstream failure left every fee blank — indistinguishable from a term that
 * genuinely carries no fee (ABN-512).
 *
 * Cached like the merchant record and for the same reason: the entry never
 * expires, so a merchant whose key or upstream stops answering keeps the
 * figures they last saw rather than losing the column. A caller is always
 * told whether what it got is fresh, so the screen can say so.
 *
 * Keyed on mode + API key + buyer country + the requested terms, since every
 * one of those changes the answer.
 */
class FeeRatesProvider
{
    public const ENDPOINT = '/pricing/v1/merchant/rates';

    private const CACHE_KEY_PREFIX = 'two_gateway_merchant_fee_rates_';

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

        $normalised = $this->normalise(
            $this->apiAdapter->execute(
                self::ENDPOINT,
                [
                    'buyer_country_code' => $buyerCountry,
                    // TODO: no admin recourse-pricing config exists yet.
                    'recourse_pricing' => false,
                    // payout_schedule intentionally omitted — server infers from
                    // the merchant's payee accounts. Only set if/when we expose
                    // an explicit override in admin config.
                    'net_terms' => array_values($terms),
                ],
                'POST',
                $storeId
            )
        );

        if ($normalised['success']) {
            $normalised['fetched_at'] = time();
            $normalised['stale'] = false;
            if ($cacheKey !== null) {
                // Null lifetime: the entry never expires, so nothing but a
                // successful fetch or a manual flush can take it away.
                $this->cache->save($this->json->serialize($normalised), $cacheKey, self::CACHE_TAGS, null);
            }
            return $normalised;
        }

        $cached = $cacheKey === null ? null : $this->loadRates($cacheKey);
        if ($cached === null) {
            return $normalised;
        }
        $this->logRepository->addDebugLog(
            'FeeRatesProvider: serving the last retrieved fee set',
            ['fetched_at' => $cached['fetched_at'] ?? null]
        );
        $cached['stale'] = true;

        return $cached;
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

        return [
            'success' => true,
            'currency' => (string)($response['currency'] ?? ''),
            'fees' => $fees,
        ];
    }
}
