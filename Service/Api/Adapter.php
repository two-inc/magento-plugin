<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Service\Api;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\HTTP\Client\CurlFactory;
use Throwable;
use Two\Gateway\Api\ApiCall;
use Two\Gateway\Api\ApiResult;
use Two\Gateway\Api\ApiTranslatorInterface;
use Two\Gateway\Api\BrandRegistryInterface;
use Two\Gateway\Api\Config\RepositoryInterface as ConfigRepository;
use Two\Gateway\Api\Log\RepositoryInterface as LogRepository;
use Two\Gateway\Api\Webapi\SoleTraderInterface;

/**
 * Api Adapter
 */
class Adapter
{
    /**
     * @var ConfigRepository
     */
    private $configRepository;

    /** @var BrandRegistryInterface */
    private $brandRegistry;

    /**
     * @var CurlFactory
     */
    private $curlFactory;

    /**
     * @var LogRepository
     */
    private $logRepository;

    /** @var ApiTranslatorInterface */
    private $apiTranslator;

    public function __construct(
        ConfigRepository $configRepository,
        BrandRegistryInterface $brandRegistry,
        CurlFactory $curlFactory,
        LogRepository $logRepository,
        ApiTranslatorInterface $apiTranslator
    ) {
        $this->configRepository = $configRepository;
        $this->brandRegistry = $brandRegistry;
        $this->curlFactory = $curlFactory;
        $this->logRepository = $logRepository;
        $this->apiTranslator = $apiTranslator;
    }

    /**
     * Send request to api
     *
     * @param string $endpoint
     * @param array $payload
     * @param string $method
     * @param int|null $storeId Optional store scope for API key resolution (default: default scope)
     * @return array
     */
    public function execute(
        string $endpoint,
        array $payload = [],
        string $method = 'POST',
        ?int $storeId = null
    ): array {
        try {
            $this->logRepository->addDebugLog(sprintf('API call: %s %s', $method, $endpoint), $payload);
            $mode = $storeId !== null ? $this->configRepository->getMode($storeId) : null;
            $url = $this->configRepository->addVersionDataInURL(
                sprintf('%s%s', $this->configRepository->getCheckoutApiUrl($mode), $endpoint)
            );
            $body = ($method == "POST" || $method == "PUT")
                ? (empty($payload) ? '' : (string)json_encode($payload))
                : '';
            $call = new ApiCall(
                $method,
                $url,
                [
                    'Content-Type' => 'application/json',
                    'X-API-Key' => $this->configRepository->getApiKey($storeId),
                ],
                $body
            );

            try {
                $call = $this->apiTranslator->translateRequest($call);
            } catch (Throwable $e) {
                return $this->translatorFailure('request', $e, $endpoint, $method);
            }

            $curl = $this->curlFactory->create();
            foreach ($call->headers as $name => $value) {
                $curl->addHeader($name, $value);
            }
            $curl->setOption(CURLOPT_RETURNTRANSFER, true);
            $curl->setOption(CURLOPT_SSL_VERIFYHOST, 0);
            $curl->setOption(CURLOPT_SSL_VERIFYPEER, 0);
            $curl->setOption(CURLOPT_TIMEOUT, 60);

            if ($call->method == "POST" || $call->method == "PUT") {
                $curl->addHeader("Content-Length", strlen($call->body));
                $curl->post($call->url, $call->body);
            } else {
                $curl->setOption(CURLOPT_FOLLOWLOCATION, true);
                $curl->get($call->url);
            }

            $result = new ApiResult(
                (int)$curl->getStatus(),
                $curl->getHeaders() ?: [],
                (string)$curl->getBody()
            );

            try {
                $result = $this->apiTranslator->translateResponse($result);
            } catch (Throwable $e) {
                return $this->translatorFailure('response', $e, $endpoint, $method);
            }

            $body = trim($result->body);
            if (in_array($result->status, [200, 201, 202])) {
                $decoded = [];
                if ((!$body || $body === '""')) {
                    if (in_array($endpoint, [
                            SoleTraderInterface::DELEGATION_TOKEN_ENDPOINT,
                            SoleTraderInterface::AUTOFILL_TOKEN_ENDPOINT])) {
                        $decoded = $result->headers;
                        foreach ($decoded as $key => $value) {
                            $decoded[strtolower($key)] = $value;
                        }
                    }
                } else {
                    $decoded = json_decode($body, true);
                }
                $this->logRepository->addDebugLog(
                    sprintf('API response %s %s (status: %s)', $method, $endpoint, $result->status),
                    $decoded
                );
                return $decoded;
            } else {
                if ($body) {
                    $decoded = json_decode($body, true) ?: [];
                    $decoded['http_status'] = $result->status;
                    $this->logRepository->addDebugLog(
                        sprintf('API response %s %s (status: %s)', $method, $endpoint, $result->status),
                        $decoded
                    );
                    return $decoded;
                } else {
                    $this->logRepository->addDebugLog(
                        sprintf('API response %s %s (status: %s)', $method, $endpoint, $result->status),
                        'Invalid API response.'
                    );
                    throw new LocalizedException(
                        __('Invalid API response from %1.', $this->brandRegistry->getProductName())
                    );
                }
            }
        } catch (Throwable $exception) {
            return [
                'error_code' => 400,
                'error_message' => $exception->getMessage(),
            ];
        }
    }

    private function translatorFailure(string $phase, Throwable $e, string $endpoint, string $method): array
    {
        $this->logRepository->addErrorLog(
            sprintf(
                '[api-translator-failure] phase=%s class=%s endpoint=%s method=%s message=%s',
                $phase,
                get_class($this->apiTranslator),
                $endpoint,
                $method,
                $e->getMessage()
            ),
            null
        );
        return [
            'error_code' => 502,
            'http_status' => 502,
            'error_source' => 'api_translator',
            'error_message' => 'Translator failure',
        ];
    }
}
