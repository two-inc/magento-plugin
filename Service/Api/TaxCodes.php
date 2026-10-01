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
use Two\Gateway\Model\Cache\Type\TwoGateway;

/**
 * The tax codes a merchant may map a tax class to, from
 * GET /v1/tax_codes/{country} (TWO-24877).
 *
 * A successful answer is cached per country and store for a day: the list
 * changes only when Two deploys. A failure is not cached, and there is no
 * built-in fallback list, so the caller shows the error instead.
 *
 * Codes that need a caller-supplied exemption reason and have no default are
 * left out, since the mapping has nowhere to capture one. A merchant who
 * needs such a code can set it, with its reason, in the postprocessing hook.
 */
class TaxCodes
{
    private const ENDPOINT = '/v1/tax_codes/%s';
    private const CACHE_KEY_PREFIX = 'two_gateway_tax_codes_';
    private const CACHE_LIFETIME = 86400;

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
     * @param string $countryCode the merchant's country, ISO 3166-1 alpha-2
     * @param int|null $storeId store whose API key the call authenticates with
     * @return array<int, array{code: string, name: string, rate: string}>|null null when the list cannot be read
     */
    public function getSelectable(string $countryCode, ?int $storeId = null): ?array
    {
        $countryCode = strtoupper(trim($countryCode));
        if (!preg_match('/^[A-Z]{2}$/', $countryCode)) {
            return null;
        }

        $cacheKey = self::CACHE_KEY_PREFIX . $countryCode . '_' . ($storeId ?? 'default');
        $cached = $this->cache->load($cacheKey);
        if ($cached !== false) {
            $codes = $this->json->unserialize($cached);
            if (is_array($codes)) {
                return $codes;
            }
        }

        $codes = $this->fetch($countryCode, $storeId);
        if ($codes !== null) {
            $this->cache->save($this->json->serialize($codes), $cacheKey, [TwoGateway::CACHE_TAG], self::CACHE_LIFETIME);
        }

        return $codes;
    }

    /**
     * @return array<int, array{code: string, name: string, rate: string}>|null
     */
    private function fetch(string $countryCode, ?int $storeId): ?array
    {
        $response = $this->apiAdapter->execute(sprintf(self::ENDPOINT, $countryCode), [], 'GET', $storeId);
        if (isset($response['error_code']) || isset($response['http_status'])
            || !isset($response['data']) || !is_array($response['data'])
        ) {
            $this->logRepository->addDebugLog('TaxCodes: list could not be read for ' . $countryCode, $response);
            return null;
        }

        $codes = [];
        foreach ($response['data'] as $entry) {
            if (!is_array($entry) || !is_string($entry['code'] ?? null) || $entry['code'] === '') {
                continue;
            }
            if (!empty($entry['requires_exemption_reason']) && ($entry['exemption_reason_code'] ?? null) === null) {
                continue;
            }
            $codes[] = [
                'code' => $entry['code'],
                'name' => is_string($entry['display_name'] ?? null) ? $entry['display_name'] : '',
                'rate' => is_scalar($entry['rate'] ?? null) ? (string)$entry['rate'] : '',
            ];
        }

        return $codes;
    }
}
