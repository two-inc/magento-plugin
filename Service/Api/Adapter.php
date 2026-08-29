<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Service\Api;

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
     * @param string|null $apiKeyOverride Key to authenticate with instead of the stored one, for
     *        verifying a candidate key that has not been saved yet
     * @param string|null $modeOverride Environment to call instead of the stored one, for verifying
     *        a candidate key against a mode submitted in the same admin save
     * @return array
     */
    public function execute(
        string $endpoint,
        array $payload = [],
        string $method = 'POST',
        ?int $storeId = null,
        ?string $apiKeyOverride = null,
        ?string $modeOverride = null
    ): array {
        return $this->executeWithStatus($endpoint, $payload, $method, $storeId, $apiKeyOverride, $modeOverride)['body'];
    }

    /**
     * Same call as execute(), keeping the upstream HTTP status alongside the
     * decoded body. execute() discards it, which leaves a caller unable to
     * tell a 2xx apart from a 200-shaped body — the proxy routes relay the
     * pass/fail distinction the browser used to read off its own request.
     *
     * A status of 0 means no HTTP exchange completed at all.
     *
     * @see self::execute() for the parameters
     * @return array{status: int, body: array}
     */
    public function executeWithStatus(
        string $endpoint,
        array $payload = [],
        string $method = 'POST',
        ?int $storeId = null,
        ?string $apiKeyOverride = null,
        ?string $modeOverride = null
    ): array {
        try {
            $this->logRepository->addDebugLog(sprintf('API call: %s %s', $method, $endpoint), $payload);
            $mode = $modeOverride
                ?: ($storeId !== null ? $this->configRepository->getMode($storeId) : null);
            $url = $this->configRepository->addVersionDataInURL(
                sprintf('%s%s', $this->configRepository->getCheckoutApiUrl($mode), $endpoint)
            );
            $body = ($method == "POST" || $method == "PUT")
                ? (empty($payload) ? '' : (string)json_encode($payload))
                : '';
            $headers = [
                'Content-Type' => 'application/json',
                'X-API-Key' => $apiKeyOverride ?? $this->configRepository->getApiKey($storeId),
            ];
            // Server-side calls always carry the token when one is configured —
            // the browser toggle governs only the browser's own direct call.
            $firewallToken = $this->configRepository->getFirewallToken($storeId);
            if ($firewallToken !== '') {
                $headers['X-WAF-TOKEN'] = $firewallToken;
            }
            $call = new ApiCall($method, $url, $headers, $body);

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
            // TWO-25386: TLS verification is ON by default (secure). Only the
            // "Disable SSL verification" debug toggle turns it off, for stores
            // behind a corporate proxy that terminates TLS with its own
            // certificate. Previously this was unconditionally disabled here.
            if ($this->configRepository->isSslVerificationDisabled($storeId)) {
                $curl->setOption(CURLOPT_SSL_VERIFYHOST, 0);
                $curl->setOption(CURLOPT_SSL_VERIFYPEER, 0);
            } else {
                $curl->setOption(CURLOPT_SSL_VERIFYHOST, 2);
                $curl->setOption(CURLOPT_SSL_VERIFYPEER, true);
            }
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
                return ['status' => $result->status, 'body' => $decoded];
            } else {
                if ($body) {
                    $decoded = json_decode($body, true) ?: [];
                    $decoded['http_status'] = $result->status;
                    $this->logRepository->addDebugLog(
                        sprintf('API response %s %s (status: %s)', $method, $endpoint, $result->status),
                        $decoded
                    );
                    return ['status' => $result->status, 'body' => $decoded];
                } else {
                    $this->logRepository->addDebugLog(
                        sprintf('API response %s %s (status: %s)', $method, $endpoint, $result->status),
                        'Invalid API response.'
                    );
                    // This used to throw a LocalizedException, which this
                    // method's own catch-all immediately converted into
                    // exactly the array below minus `http_status` — the
                    // throw never escaped execute(). Returning directly
                    // keeps that same shape while preserving the real HTTP
                    // status, which the catch-all discarded. Callers that
                    // categorise failures (Service\Merchant\ApiKeyStatus)
                    // need it: without a status, an empty-bodied 5xx is
                    // indistinguishable from a transport failure, and a
                    // service outage would be reported to the merchant as
                    // "unreachable" instead of "the service errored".
                    return [
                        'status' => $result->status,
                        'body' => [
                            'error_code' => 400,
                            'http_status' => $result->status,
                            'error_message' => (string)__(
                                'Invalid API response from %1.',
                                $this->brandRegistry->getProductName()
                            ),
                        ],
                    ];
                }
            }
        } catch (Throwable $exception) {
            // Logged here because the anonymous proxy routes replace this
            // body with a generic message: the transport detail (cURL, DNS,
            // TLS, host names) is for the merchant's log, not the caller.
            $this->logRepository->addErrorLog(
                sprintf('[api-transport-failure] endpoint=%s method=%s', $endpoint, $method),
                $exception->getMessage()
            );
            return [
                'status' => 0,
                'body' => [
                    'error_code' => 400,
                    'error_message' => $exception->getMessage(),
                ],
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
            'status' => 502,
            'body' => [
                'error_code' => 502,
                'http_status' => 502,
                'error_source' => 'api_translator',
                'error_message' => 'Translator failure',
            ],
        ];
    }
}
