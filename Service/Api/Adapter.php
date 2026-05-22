<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Service\Api;

use Magento\Framework\App\State;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\HTTP\Client\CurlFactory;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Throwable;
use Two\Gateway\Api\BrandRegistryInterface;
use Two\Gateway\Api\Config\RepositoryInterface as ConfigRepository;
use Two\Gateway\Api\Log\RepositoryInterface as LogRepository;
use Two\Gateway\Api\Operation;
use Two\Gateway\Api\TranslatorInterface;

/**
 * Outbound HTTP chokepoint. See docs/proxy-translator-design.md §5.
 */
class Adapter
{
    /** @var ConfigRepository */
    private $configRepository;

    /** @var BrandRegistryInterface */
    private $brandRegistry;

    /** @var CurlFactory */
    private $curlFactory;

    /** @var LogRepository */
    private $logRepository;

    /** @var TranslatorInterface */
    private $translator;

    /** @var RequestFactoryInterface */
    private $requestFactory;

    /** @var StreamFactoryInterface */
    private $streamFactory;

    /** @var ResponseFactoryInterface */
    private $responseFactory;

    /** @var State */
    private $appState;

    /** @var int translator-CPU WARN threshold in ms */
    private $translatorWarnMs;

    public function __construct(
        ConfigRepository $configRepository,
        BrandRegistryInterface $brandRegistry,
        CurlFactory $curlFactory,
        LogRepository $logRepository,
        TranslatorInterface $translator,
        RequestFactoryInterface $requestFactory,
        StreamFactoryInterface $streamFactory,
        ResponseFactoryInterface $responseFactory,
        State $appState,
        int $translatorWarnMs = 100
    ) {
        $this->configRepository = $configRepository;
        $this->brandRegistry = $brandRegistry;
        $this->curlFactory = $curlFactory;
        $this->logRepository = $logRepository;
        $this->translator = $translator;
        $this->requestFactory = $requestFactory;
        $this->streamFactory = $streamFactory;
        $this->responseFactory = $responseFactory;
        $this->appState = $appState;
        $this->translatorWarnMs = $translatorWarnMs;
    }

    /**
     * Send request to API.
     *
     * @param string   $endpoint  path segment, e.g. '/v1/order'
     * @param array    $payload   JSON-serialisable
     * @param string   $method    HTTP method (POST/PUT/GET/...)
     * @param int|null $storeId   optional store scope for API-key resolution
     * @param string   $operation required {@see Operation}::* constant
     */
    public function execute(
        string $endpoint,
        array $payload,
        string $method,
        ?int $storeId,
        string $operation
    ): array {
        $this->logRepository->addDebugLog(sprintf('API call: %s %s', $method, $endpoint), $payload);

        $mode = $storeId !== null ? $this->configRepository->getMode($storeId) : null;
        $nativeUrl = $this->configRepository->addVersionDataInURL(
            sprintf('%s%s', $this->configRepository->getCheckoutApiUrl($mode), $endpoint)
        );

        // Methods that carry a body in this codebase. PATCH/DELETE/HEAD have no current
        // callers; admitting them here without a matching dispatch branch would silently
        // send them as POST via Magento Curl::post(). PUT dispatches via Curl::post()
        // too — pre-existing behaviour preserved verbatim, see Observer/SalesOrderAddressUpdate
        // for the only PUT caller.
        $hasBody = in_array($method, ['POST', 'PUT'], true);

        // Encode payload up front; surface a fatal encoding error explicitly rather
        // than silently shipping an empty body. NaN/INF/non-UTF-8 → json_encode = false.
        $bodyString = '';
        if ($hasBody && !empty($payload)) {
            $encoded = json_encode($payload);
            if ($encoded === false) {
                $this->logRepository->addDebugLog(
                    sprintf('[two.api] op=%s json_encode failed: %s', $operation, json_last_error_msg()),
                    null
                );
                return [
                    'error_code' => 400,
                    'error_source' => 'two',
                    'error_message' => 'Outbound payload could not be encoded as JSON',
                ];
            }
            $bodyString = $encoded;
        }

        // §5.1 step 1-4 — build PSR-7 Request with headers set BEFORE translation.
        $request = $this->requestFactory->createRequest($method, $nativeUrl)
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('X-API-Key', $this->configRepository->getApiKey($storeId))
            ->withHeader(TranslatorInterface::OP_HEADER, $operation);
        if ($hasBody) {
            $request = $request->withBody($this->streamFactory->createStream($bodyString));
        }
        $preTranslationRequest = $request;

        // §5.1 step 5 — translateRequest.
        $translateReqStart = microtime(true);
        try {
            $request = $this->translator->translateRequest($request);
        } catch (Throwable $e) {
            return $this->translatorFailure('request', $e, $operation, $nativeUrl, $translateReqStart);
        }
        $translateReqMs = (int)round((microtime(true) - $translateReqStart) * 1000);

        // §5.1 step 6 — restore preserve-list headers stripped by translator.
        $request = $this->restorePreservedHeaders($request, $preTranslationRequest, $operation);

        // §5.1 step 7 — strip OP_HEADER post-translation.
        $request = $request->withoutHeader(TranslatorInterface::OP_HEADER);

        // §5.1 step 8 — strip Content-Length AND Host; recomputed/derived by Curl.
        // PSR-7 mandates a Host header; if translator did withUri() the explicit
        // Host would diverge from the URL — let libcurl set it from the URL.
        $request = $request->withoutHeader('Content-Length')->withoutHeader('Host');

        // §5.1 step 9 — fresh Curl per call (no state leakage between requests).
        $curl = $this->curlFactory->create();

        // §5.1 step 10 — unpack PSR-7 headers → Curl. Magento Curl::addHeader keys by
        // name (single value); use getHeaderLine() to honour RFC-7230 multi-value joining.
        foreach ($request->getHeaders() as $name => $_) {
            $curl->addHeader($name, $request->getHeaderLine($name));
        }

        // §5.1 step 11 — Content-Length only for body methods. Defensive rewind in
        // case translator's translateRequest read but did not rewind the body stream.
        $reqBody = $request->getBody();
        if ($reqBody->isSeekable()) {
            $reqBody->rewind();
        }
        $postBody = (string)$reqBody;
        if ($hasBody) {
            $curl->addHeader('Content-Length', (string)strlen($postBody));
        }

        // §5.1 step 12 — curl options. NOT CURLOPT_HEADER (Magento Curl uses its own
        // CURLOPT_HEADERFUNCTION callback; setting CURLOPT_HEADER would corrupt body).
        $curl->setOption(CURLOPT_RETURNTRANSFER, true);
        $curl->setOption(CURLOPT_SSL_VERIFYHOST, 0);
        $curl->setOption(CURLOPT_SSL_VERIFYPEER, 0);
        $curl->setOption(CURLOPT_TIMEOUT, 60);

        // §5.1 step 13 — dispatch.
        $dispatchedUrl = (string)$request->getUri();
        $dispatchStart = microtime(true);
        try {
            if ($hasBody) {
                $curl->post($dispatchedUrl, $postBody);
            } else {
                $curl->setOption(CURLOPT_FOLLOWLOCATION, true);
                $curl->get($dispatchedUrl);
            }
        } catch (Throwable $e) {
            return $this->upstreamFailure(
                $e,
                $operation,
                $nativeUrl,
                $dispatchedUrl,
                $translateReqMs,
                (int)round((microtime(true) - $dispatchStart) * 1000)
            );
        }
        $dispatchMs = (int)round((microtime(true) - $dispatchStart) * 1000);

        // §5.1 step 14 — assemble PSR-7 Response. Magento Curl::getHeaders() returns
        // $_responseHeaders (verified against upstream lib/internal source). Header
        // values are scalar strings (Magento overwrites on duplicate name); multi-value
        // response headers like Set-Cookie are flattened by the transport, not by us.
        $status = (int)$curl->getStatus();
        $preTranslationBody = (string)$curl->getBody();
        $response = $this->responseFactory->createResponse($status)
            ->withBody($this->streamFactory->createStream($preTranslationBody));
        $responseHeadersPre = $curl->getHeaders() ?: [];
        foreach ($responseHeadersPre as $name => $value) {
            // Magento Curl historically returns scalar string values (duplicates
            // overwrite), but newer minors may surface array-valued headers like
            // multi-Set-Cookie; PSR-7 withHeader accepts string|string[].
            $response = $response->withHeader($name, is_array($value) ? $value : (string)$value);
        }

        // §5.1 step 15 — translateResponse.
        $translateRespStart = microtime(true);
        try {
            $response = $this->translator->translateResponse($response);
        } catch (Throwable $e) {
            return $this->translatorFailure(
                'response',
                $e,
                $operation,
                $nativeUrl,
                $translateRespStart,
                $dispatchedUrl,
                $translateReqMs,
                $dispatchMs
            );
        }
        $translateRespMs = (int)round((microtime(true) - $translateRespStart) * 1000);

        // §5.1 step 16 — materialise body ONCE. Stream cast-to-string is destructive;
        // do it here and operate on the string from this point forward.
        $body = $response->getBody();
        if ($body->isSeekable()) {
            $body->rewind();
        }
        $bodyAfter = (string)$body;
        $bodyTrim = trim($bodyAfter);

        // §5.4 empty-body guard: translator emptied a non-empty wire body on a
        // body-reading op. Status 204 exempt; wire-empty pre-translation is OK.
        if ($status !== 204
            && !$this->isHeaderReadingOp($operation)
            && trim($preTranslationBody) !== ''
            && $bodyTrim === ''
        ) {
            $e = new \RuntimeException('translator returned empty body for body-reading op');
            return $this->translatorFailure(
                'response',
                $e,
                $operation,
                $nativeUrl,
                $translateRespStart,
                $dispatchedUrl,
                $translateReqMs,
                $dispatchMs
            );
        }

        // §5.1 step 17 — token-header guard for header-reading ops.
        if ($this->isHeaderReadingOp($operation)) {
            $missing = $this->missingTokenHeaders($response, $responseHeadersPre);
            if ($missing !== []) {
                $e = new \RuntimeException(
                    'translator stripped required token response header(s): ' . implode(',', $missing)
                );
                return $this->translatorFailure(
                    'response',
                    $e,
                    $operation,
                    $nativeUrl,
                    $translateRespStart,
                    $dispatchedUrl,
                    $translateReqMs,
                    $dispatchMs
                );
            }
        }

        // §5.1 step 18 — run existing success/error logic on the materialised string.
        $finalStatus = (int)$response->getStatusCode();
        $result = $this->buildResult($finalStatus, $bodyTrim, $response, $operation, $method, $endpoint);

        $this->correlationLog(
            $operation,
            $nativeUrl,
            $dispatchedUrl,
            $finalStatus,
            $finalStatus >= 200 && $finalStatus < 300 ? 'ok' : 'two',
            $translateReqMs,
            $dispatchMs,
            $translateRespMs
        );

        return $result;
    }

    /**
     * Shape the Adapter's array return value from a (translated) PSR-7 Response.
     * Throwable thrown inside is caught and converted to a 400 envelope at the
     * boundary so callers see a consistent failure shape. The outer execute()
     * still emits a correlationLog line after this returns regardless of branch.
     */
    private function buildResult(
        int $status,
        string $bodyAfter,
        ResponseInterface $response,
        string $operation,
        string $method,
        string $endpoint
    ): array {
        try {
            // 204 No Content is a legitimate success; the empty-body guard already
            // exempts it. Include here so callers do not see a fabricated 400.
            if (in_array($status, [200, 201, 202, 204], true)) {
                $result = [];
                if ($bodyAfter === '' || $bodyAfter === '""') {
                    if ($this->isHeaderReadingOp($operation)) {
                        $result = $this->responseHeadersAsLowercaseArray($response);
                    }
                } else {
                    $decoded = json_decode($bodyAfter, true);
                    $result = is_array($decoded) ? $decoded : [];
                }
                $this->logRepository->addDebugLog(
                    sprintf('API response %s %s (status: %s)', $method, $endpoint, $status),
                    $result
                );
                return $result;
            }

            if ($bodyAfter !== '') {
                // Defensive: bare-scalar JSON responses (e.g. body == "0", "false",
                // "\"err\"") decode to non-array values. The legacy `?: []` pattern
                // would have kept them and then fatal on offset assignment below.
                $decoded = json_decode($bodyAfter, true);
                $result = is_array($decoded) ? $decoded : [];
                $result['http_status'] = $status;
                $this->logRepository->addDebugLog(
                    sprintf('API response %s %s (status: %s)', $method, $endpoint, $status),
                    $result
                );
                return $result;
            }

            $this->logRepository->addDebugLog(
                sprintf('API response %s %s (status: %s)', $method, $endpoint, $status),
                'Invalid API response.'
            );
            throw new LocalizedException(
                __('Invalid API response from %1.', $this->brandRegistry->getProductName())
            );
        } catch (Throwable $exception) {
            return [
                'error_code' => 400,
                'error_source' => 'two',
                'error_message' => $exception->getMessage(),
            ];
        }
    }

    /**
     * Visible for unit-testing the restoration path without faking the whole
     * execute() pipeline. Kept protected (not private) for that reason; do not
     * downgrade visibility without also rewriting the §11.8 unit test.
     */
    protected function restorePreservedHeaders(
        RequestInterface $request,
        RequestInterface $preTranslation,
        string $operation
    ): RequestInterface {
        foreach (TranslatorInterface::PRESERVE_HEADERS as $name) {
            if ($preTranslation->hasHeader($name) && !$request->hasHeader($name)) {
                $request = $request->withHeader($name, $preTranslation->getHeaderLine($name));
                $this->logRepository->addDebugLog(
                    sprintf('[translator] restored stripped header op=%s header=%s', $operation, $name),
                    null
                );
            }
        }
        foreach ($preTranslation->getHeaders() as $name => $_) {
            foreach (TranslatorInterface::PRESERVE_HEADER_PREFIXES as $prefix) {
                if (stripos($name, $prefix) === 0 && !$request->hasHeader($name)) {
                    $request = $request->withHeader($name, $preTranslation->getHeaderLine($name));
                    $this->logRepository->addDebugLog(
                        sprintf('[translator] restored stripped header op=%s header=%s', $operation, $name),
                        null
                    );
                }
            }
        }
        return $request;
    }

    private function isHeaderReadingOp(string $operation): bool
    {
        return in_array($operation, [Operation::DELEGATION_TOKEN, Operation::AUTOFILL_TOKEN], true);
    }

    /**
     * Token-endpoint legacy shape: callers index by lowercase header name.
     */
    private function responseHeadersAsLowercaseArray(ResponseInterface $response): array
    {
        $out = [];
        foreach ($response->getHeaders() as $name => $_) {
            $out[strtolower((string)$name)] = $response->getHeaderLine($name);
        }
        return $out;
    }

    /**
     * Names from TOKEN_RESPONSE_HEADERS present in pre-translation Curl headers
     * but absent from post-translation PSR-7 Response.
     */
    private function missingTokenHeaders(ResponseInterface $response, array $preHeaders): array
    {
        $preLower = [];
        foreach ($preHeaders as $name => $_) {
            $preLower[strtolower((string)$name)] = true;
        }
        $missing = [];
        foreach (TranslatorInterface::TOKEN_RESPONSE_HEADERS as $required) {
            $needle = strtolower($required);
            if (!isset($preLower[$needle])) {
                continue; // upstream didn't send it; not the translator's fault
            }
            if (!$response->hasHeader($required)) {
                $missing[] = $required;
            }
        }
        return $missing;
    }

    /**
     * FIXME: split into translatorRequestFailure + translatorResponseFailure once
     * the API stabilises. The shared body multiplexes two distinct call shapes via
     * the `$phase` discriminator; works correctly today but is fragile to refactor.
     */
    private function translatorFailure(
        string $phase,
        Throwable $e,
        string $operation,
        string $nativeUrl,
        float $phaseStart,
        string $dispatchedUrl = '',
        int $translateReqMs = 0,
        int $dispatchMs = 0
    ): array {
        $translateRespMs = $phase === 'response'
            ? (int)round((microtime(true) - $phaseStart) * 1000)
            : 0;
        if ($phase === 'request') {
            $translateReqMs = (int)round((microtime(true) - $phaseStart) * 1000);
        }
        $this->logRepository->addDebugLog(
            sprintf(
                '[translator-failure] phase=%s class=%s op=%s message=%s',
                $phase,
                get_class($this->translator),
                $operation,
                json_encode($e->getMessage())
            ),
            null
        );
        $this->correlationLog(
            $operation,
            $nativeUrl,
            $dispatchedUrl,
            0,
            'translator',
            $translateReqMs,
            $dispatchMs,
            $translateRespMs
        );
        return [
            'error_code' => 502,
            'http_status' => 502,
            'error_source' => 'translator',
            'error_message' => 'Translator failure',
        ];
    }

    private function upstreamFailure(
        Throwable $e,
        string $operation,
        string $nativeUrl,
        string $dispatchedUrl,
        int $translateReqMs,
        int $dispatchMs
    ): array {
        $this->correlationLog(
            $operation,
            $nativeUrl,
            $dispatchedUrl,
            0,
            'two',
            $translateReqMs,
            $dispatchMs,
            0
        );
        return [
            'error_code' => 400,
            'error_source' => 'two',
            'error_message' => $e->getMessage(),
        ];
    }

    private function correlationLog(
        string $op,
        string $nativeUrl,
        string $dispatchedUrl,
        int $status,
        string $errorSource,
        int $translateReqMs,
        int $dispatchMs,
        int $translateRespMs
    ): void {
        $line = sprintf(
            '[two.api] op=%s native_url=%s dispatched_url=%s translator=%s status=%d '
            . 'error_source=%s translate_req_ms=%d dispatch_ms=%d translate_resp_ms=%d',
            $op,
            $nativeUrl,
            $dispatchedUrl,
            get_class($this->translator),
            $status,
            $errorSource,
            $translateReqMs,
            $dispatchMs,
            $translateRespMs
        );
        $warnTranslator = ($translateReqMs > $this->translatorWarnMs)
            || ($translateRespMs > $this->translatorWarnMs);
        if ($warnTranslator) {
            $this->logRepository->addDebugLog('[WARN] ' . $line, null);
        } else {
            $this->logRepository->addDebugLog($line, null);
        }

    }

    private function isDeveloperMode(): bool
    {
        try {
            return $this->appState->getMode() === State::MODE_DEVELOPER;
        } catch (Throwable $e) {
            return false;
        }
    }
}
