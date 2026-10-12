<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Service\Api;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Two\Gateway\Api\Config\RepositoryInterface as ConfigRepository;
use Two\Gateway\Api\Log\RepositoryInterface as LogRepository;
use Two\Gateway\Model\Cache\Type\TwoGateway;
use Two\Gateway\Model\Config\AdminScope;
use Two\Gateway\Model\Config\TaxCodeSection;

/**
 * The tax codes the API lists for a country, from GET /v1/tax_codes/{country}
 * (TWO-24877), with whether a code is mandatory on a 0% line there
 * (TWO-26153). Admin only: order composition never reads it.
 *
 * An answer is cached per country and environment for a day: the list
 * changes only when the API deploys. A country with no code registry (400
 * COUNTRY_NOT_SUPPORTED) is an answer too, cached the same way: no codes, not
 * mandatory. Every answer is also kept as the country's last answer, with no
 * expiry, and a failed fetch serves it whatever its age. Fetches for one
 * country are at least RETRY_FLOOR apart, stamped before the call, so an
 * outage costs one attempt per floor rather than one per page load. An entry
 * cached without the metadata counts as none.
 *
 * Codes that need a caller-supplied exemption reason and have no default are
 * left out, since the mapping has nowhere to capture one. A merchant who
 * needs such a code can set it, with its reason, in the postprocessing hook.
 */
class TaxCodes
{
    /** Tags the day-long answers and the retry floors, so an expiry can drop them and keep the last answers. */
    public const CACHE_TAG = 'TWO_GATEWAY_TAX_CODES';

    private const ENDPOINT = '/v1/tax_codes/%s';
    private const CACHE_KEY_PREFIX = 'two_gateway_tax_codes_';
    private const LAST_SUFFIX = '_last';
    private const TRIED_SUFFIX = '_tried';
    private const CACHE_LIFETIME = 86400;
    private const RETRY_FLOOR = 300;
    private const FETCH_TIMEOUT_SECONDS = 10;

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
     * @var ConfigRepository
     */
    private $configRepository;

    public function __construct(
        Adapter $apiAdapter,
        CacheInterface $cache,
        Json $json,
        LogRepository $logRepository,
        ConfigRepository $configRepository
    ) {
        $this->apiAdapter = $apiAdapter;
        $this->cache = $cache;
        $this->json = $json;
        $this->logRepository = $logRepository;
        $this->configRepository = $configRepository;
    }

    /**
     * @param string $countryCode the merchant's country, ISO 3166-1 alpha-2
     * @param int|null $scopeId id of the scope whose API key and environment the call uses
     * @param string|null $scope scope type, as AdminScope resolves it; null is store scope
     * @param int|null $failedStatus set to the failed fetch's HTTP status (0 when none) when there is no answer
     * @return array{codes: array<int, array{code: string, name: string, rate: string}>, mandatory: bool}|null
     *     null when there is no answer
     */
    public function getList(
        string $countryCode,
        ?int $scopeId = null,
        ?string $scope = null,
        ?int &$failedStatus = null
    ): ?array {
        $failedStatus = 0;
        $countryCode = strtoupper(trim($countryCode));
        if (!preg_match('/^[A-Z]{2}$/', $countryCode)) {
            return null;
        }

        // Sandbox and production answer separately; the list is the country's, whoever asks.
        $mode = $this->configRepository->getMode($scopeId, $scope);
        $cacheKey = self::CACHE_KEY_PREFIX . $countryCode . '_' . $mode;
        $fresh = $this->load($cacheKey);
        if ($fresh !== null) {
            return $fresh;
        }
        $last = $this->load($cacheKey . self::LAST_SUFFIX);
        if ($this->cache->load($cacheKey . self::TRIED_SUFFIX) !== false) {
            return $last;
        }
        $this->cache->save('1', $cacheKey . self::TRIED_SUFFIX, $this->tags(), self::RETRY_FLOOR);

        $response = $this->apiAdapter->executeWithStatus(
            sprintf(self::ENDPOINT, $countryCode),
            [],
            'GET',
            AdminScope::isStoreScope($scope) ? $scopeId : null,
            $this->configRepository->getApiKey($scopeId, $scope),
            $mode,
            self::FETCH_TIMEOUT_SECONDS
        );
        $list = $this->answer($response);
        if ($list !== null) {
            $serialised = $this->json->serialize($list);
            $this->cache->save($serialised, $cacheKey, $this->tags(), self::CACHE_LIFETIME);
            $this->cache->save($serialised, $cacheKey . self::LAST_SUFFIX, [TwoGateway::CACHE_TAG], null);

            return $list;
        }

        $status = (int)($response['status'] ?? 0);
        $this->logRepository->addLog(
            sprintf('TaxCodes: GET /v1/tax_codes/%s failed (HTTP %d); the tax code section shows a notice', $countryCode, $status),
            []
        );
        if ($last === null) {
            $failedStatus = $status;
        }

        return $last;
    }

    /**
     * Expire every day-long answer and retry floor, as "Refresh merchant
     * profile" does, so the next render asks again. The last answers are
     * kept, so a fetch that then fails still shows them.
     */
    public function expire(): void
    {
        $this->cache->clean([self::CACHE_TAG]);
    }

    /**
     * @param array{status?: int, body?: mixed} $response
     * @return array{codes: array<int, array{code: string, name: string, rate: string}>, mandatory: bool}|null
     */
    private function answer(array $response): ?array
    {
        $status = (int)($response['status'] ?? 0);
        $body = $response['body'] ?? null;
        if (!is_array($body)) {
            return null;
        }
        if ($status === 400 && ($body['error_code'] ?? null) === 'COUNTRY_NOT_SUPPORTED') {
            return ['codes' => [], 'mandatory' => false];
        }
        if ($status !== 200 || !is_array($body['data'] ?? null)) {
            return null;
        }

        $codes = [];
        foreach ($body['data'] as $entry) {
            if (!is_array($entry) || !is_string($entry['code'] ?? null) || $entry['code'] === '') {
                continue;
            }
            $defaultReason = $entry['exemption_reason_code'] ?? null;
            if (!empty($entry['requires_exemption_reason']) && ($defaultReason === null || $defaultReason === '')) {
                continue;
            }
            $codes[] = [
                'code' => $entry['code'],
                'name' => is_string($entry['display_name'] ?? null) ? $entry['display_name'] : '',
                'rate' => is_scalar($entry['rate'] ?? null) ? (string)$entry['rate'] : '',
            ];
        }

        return ['codes' => $codes, 'mandatory' => TaxCodeSection::isMandatoryAtZero($body['metadata'] ?? null)];
    }

    /**
     * @return array{codes: array, mandatory: bool}|null
     */
    private function load(string $key): ?array
    {
        $cached = $this->cache->load($key);
        if ($cached === false) {
            return null;
        }
        try {
            $list = $this->json->unserialize($cached);
        } catch (\InvalidArgumentException $e) {
            return null;
        }

        return is_array($list) && is_array($list['codes'] ?? null) && is_bool($list['mandatory'] ?? null)
            ? $list : null;
    }

    /**
     * @return string[]
     */
    private function tags(): array
    {
        return [TwoGateway::CACHE_TAG, self::CACHE_TAG];
    }
}
