<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Service\Api;

use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\HTTP\Client\CurlFactory;
use Psr\Log\LoggerInterface;
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
    private const DEFAULT_TIMEOUT_SECONDS = 60;

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

    /** @var LoggerInterface the plugin's error log (di.xml), for refused order requests */
    private $refusalLogger;

    public function __construct(
        ConfigRepository $configRepository,
        BrandRegistryInterface $brandRegistry,
        CurlFactory $curlFactory,
        LogRepository $logRepository,
        ApiTranslatorInterface $apiTranslator,
        LoggerInterface $refusalLogger
    ) {
        $this->configRepository = $configRepository;
        $this->brandRegistry = $brandRegistry;
        $this->curlFactory = $curlFactory;
        $this->logRepository = $logRepository;
        $this->apiTranslator = $apiTranslator;
        $this->refusalLogger = $refusalLogger;
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
     * @param int|null $timeoutSeconds Total time this call may take, for a caller that must bound
     *        its own wall clock — an admin save or a storefront render
     * @param string $logSubject What the call is for ("quote 42"), named in the line logged
     *        when the API refuses an order request
     * @return array
     */
    public function execute(
        string $endpoint,
        array $payload = [],
        string $method = 'POST',
        ?int $storeId = null,
        ?string $apiKeyOverride = null,
        ?string $modeOverride = null,
        ?int $timeoutSeconds = null,
        string $logSubject = ''
    ): array {
        return $this->executeWithStatus(
            $endpoint,
            $payload,
            $method,
            $storeId,
            $apiKeyOverride,
            $modeOverride,
            $timeoutSeconds,
            $logSubject
        )['body'];
    }

    /**
     * Same call as execute(), keeping the upstream HTTP status, which the
     * proxy routes relay as their pass/fail verdict. Status 0 means no HTTP
     * exchange completed at all.
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
        ?string $modeOverride = null,
        ?int $timeoutSeconds = null,
        string $logSubject = ''
    ): array {
        try {
            $mode = $modeOverride
                ?: ($storeId !== null ? $this->configRepository->getMode($storeId) : null);
            $url = $this->configRepository->addVersionDataInURL(
                sprintf('%s%s', $this->configRepository->getCheckoutApiUrl($mode), $endpoint)
            );
            $body = strtoupper($method) !== 'GET'
                ? (empty($payload) ? '' : (string)json_encode($payload))
                : '';
            $headers = [
                'Content-Type' => 'application/json',
                'X-API-Key' => $apiKeyOverride ?? $this->configRepository->getApiKey($storeId),
            ];
            // Case-folded: a differently-cased X-API-Key is a conflict, not
            // a second header.
            $ours = array_change_key_case($headers, CASE_LOWER);
            foreach ($this->configRepository->getCustomHeaders($storeId) as $name => $value) {
                if (!isset($ours[strtolower($name)])) {
                    $headers[$name] = $value;
                }
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
            $curl->setOption(CURLOPT_TIMEOUT, $timeoutSeconds ?? self::DEFAULT_TIMEOUT_SECONDS);

            $httpMethod = strtoupper($call->method);
            if ($httpMethod === 'GET') {
                $curl->setOption(CURLOPT_FOLLOWLOCATION, true);
                $curl->get($call->url);
            } else {
                // Curl::post() always issues a POST. Every other method
                // (PUT for an order edit) must name itself, or the API
                // refuses the call with 405. Magento applies user options
                // after its own, so this overrides the POST verb while
                // post() still attaches the body (TWO-26150).
                if ($httpMethod !== 'POST') {
                    $curl->setOption(CURLOPT_CUSTOMREQUEST, $httpMethod);
                }
                $curl->addHeader("Content-Length", strlen($call->body));
                $curl->post($call->url, $call->body);
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
                $this->logExchange($method, $endpoint, $payload, $call, $result, $storeId);
                return ['status' => $result->status, 'body' => $decoded];
            } else {
                if ($body) {
                    $decoded = json_decode($body, true) ?: [];
                    $decoded['http_status'] = $result->status;
                    $this->logExchange($method, $endpoint, $payload, $call, $result, $storeId);
                    $this->logRefusal($method, $endpoint, $result, $logSubject);
                    return ['status' => $result->status, 'body' => $decoded];
                } else {
                    $this->logExchange($method, $endpoint, $payload, $call, $result, $storeId);
                    $this->logRefusal($method, $endpoint, $result, $logSubject);
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
            // Logged here because the anonymous proxy routes replace this body:
            // the transport detail is for the merchant's log, not the caller.
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

    /**
     * Logs an order request the API refused, with the API's reason and never
     * a body, whatever the debug setting: the merchant needs to know why an
     * order, edit, fulfilment, refund, cancel or confirm did not go through.
     * An intent is refused routinely (no order yet, a rate limit), so it is
     * only a warning. Reads (GET) are not order requests.
     */
    private function logRefusal(string $method, string $endpoint, ApiResult $result, string $subject): void
    {
        if ($result->status < 400
            || strtoupper($method) === 'GET'
            || !preg_match('#^/v1/order(_intent)?(/|$)#', $endpoint)
        ) {
            return;
        }
        $message = sprintf(
            '%s %s refused by the API (HTTP %d)%s: %s',
            $method,
            $endpoint,
            $result->status,
            $subject !== '' ? ' for ' . $subject : '',
            $this->rejectionReason($result->body)
        );
        if (strpos($endpoint, '/v1/order_intent') === 0) {
            $this->refusalLogger->warning($message);
        } else {
            $this->refusalLogger->error($message);
        }
    }

    /**
     * The API's own reason: its error code, message, details and each
     * validation message, or the start of a body that is not the API's
     * error shape.
     */
    private function rejectionReason(string $raw): string
    {
        $body = json_decode($raw, true);
        if (!is_array($body)) {
            return $raw === '' ? 'no response body' : substr($raw, 0, 500);
        }
        $parts = [];
        foreach (['error_code', 'error_message', 'error_details'] as $key) {
            if (isset($body[$key]) && is_string($body[$key]) && $body[$key] !== '') {
                $parts[] = $body[$key];
            }
        }
        foreach (is_array($body['error_json'] ?? null) ? $body['error_json'] : [] as $error) {
            if (is_array($error) && isset($error['msg']) && is_string($error['msg'])) {
                $loc = is_array($error['loc'] ?? null) ? implode('.', $error['loc']) . ': ' : '';
                $parts[] = $loc . $error['msg'];
            }
        }
        $reason = $parts === [] ? substr($raw, 0, 500) : implode('; ', array_unique($parts));
        if (isset($body['error_trace_id']) && is_string($body['error_trace_id']) && $body['error_trace_id'] !== '') {
            $reason .= ' [Trace ID: ' . $body['error_trace_id'] . ']';
        }
        return $reason;
    }

    /**
     * Logs one API exchange, request and response together, only when the
     * store's "Enable debug logging" is on: the bodies carry buyer details.
     * The API key and every custom header value are redacted. A refused
     * call (HTTP 400 and above) goes to the error log, the rest to the
     * debug log.
     */
    private function logExchange(
        string $method,
        string $endpoint,
        array $payload,
        ApiCall $call,
        ApiResult $result,
        ?int $storeId
    ): void {
        if (!$this->configRepository->isDebugMode($storeId)) {
            return;
        }
        $headers = $call->headers;
        $redact = array_change_key_case($this->configRepository->getCustomHeaders($storeId), CASE_LOWER);
        $redact['x-api-key'] = true;
        foreach (array_keys($headers) as $name) {
            if (isset($redact[strtolower((string)$name)])) {
                $headers[$name] = '[REDACTED]';
            }
        }
        $rawBody = $result->body;
        $decodedBody = json_decode($rawBody, true);
        $context = [
            'request' => [
                'url' => $call->url,
                'headers' => $headers,
                'body' => $payload,
            ],
            'response' => [
                'status_code' => $result->status,
                'headers' => $result->headers,
                'body' => json_last_error() === JSON_ERROR_NONE ? $decodedBody : $rawBody,
            ],
        ];
        $message = sprintf('%s %s', $method, $endpoint);
        if ($result->status >= 400) {
            $this->logRepository->addErrorLog($message, $context);
        } else {
            $this->logRepository->addDebugLog($message, $context);
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
