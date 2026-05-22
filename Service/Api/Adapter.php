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
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Throwable;
use Two\Gateway\Api\ApiTranslatorInterface;
use Two\Gateway\Api\BrandRegistryInterface;
use Two\Gateway\Api\Config\RepositoryInterface as ConfigRepository;
use Two\Gateway\Api\Log\RepositoryInterface as LogRepository;
use Two\Gateway\Api\Webapi\SoleTraderInterface;

/**
 * Api Adapter
 *
 * Outbound HTTP chokepoint. The brand-overlay translator hook is wrapped around
 * the existing Curl-based dispatch — see {@see ApiTranslatorInterface} — so the
 * default install (NullApiTranslator) behaves identically to the pre-translator
 * implementation.
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

    /** @var RequestFactoryInterface */
    private $requestFactory;

    /** @var StreamFactoryInterface */
    private $streamFactory;

    /** @var ResponseFactoryInterface */
    private $responseFactory;

    public function __construct(
        ConfigRepository $configRepository,
        BrandRegistryInterface $brandRegistry,
        CurlFactory $curlFactory,
        LogRepository $logRepository,
        ApiTranslatorInterface $apiTranslator,
        RequestFactoryInterface $requestFactory,
        StreamFactoryInterface $streamFactory,
        ResponseFactoryInterface $responseFactory
    ) {
        $this->configRepository = $configRepository;
        $this->brandRegistry = $brandRegistry;
        $this->curlFactory = $curlFactory;
        $this->logRepository = $logRepository;
        $this->apiTranslator = $apiTranslator;
        $this->requestFactory = $requestFactory;
        $this->streamFactory = $streamFactory;
        $this->responseFactory = $responseFactory;
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

            $request = $this->prepareOutboundRequest($url, $method, $payload, $storeId);
            $curl = $this->unpackToCurl($request, $method);

            if ($method == "POST" || $method == "PUT") {
                $curl->post((string)$request->getUri(), (string)$request->getBody());
            } else {
                $curl->setOption(CURLOPT_FOLLOWLOCATION, true);
                $curl->get((string)$request->getUri());
            }

            $response = $this->prepareInboundResponse($curl);

            $body = trim((string)$response->getBody());
            $status = (int)$response->getStatusCode();
            if (in_array($status, [200, 201, 202])) {
                $result = [];
                if ((!$body || $body === '""')) {
                    if (in_array($endpoint, [
                            SoleTraderInterface::DELEGATION_TOKEN_ENDPOINT,
                            SoleTraderInterface::AUTOFILL_TOKEN_ENDPOINT])) {
                        foreach ($response->getHeaders() as $key => $_) {
                            $line = $response->getHeaderLine($key);
                            $result[$key] = $line;
                            $result[strtolower($key)] = $line;
                        }
                    }
                } else {
                    $result = json_decode($body, true);
                }
                $this->logRepository->addDebugLog(
                    sprintf('API response %s %s (status: %s)', $method, $endpoint, $status),
                    $result
                );
                return $result;
            } else {
                if ($body) {
                    $result = json_decode($body, true) ?: [];
                    $result['http_status'] = $status;
                    $this->logRepository->addDebugLog(
                        sprintf('API response %s %s (status: %s)', $method, $endpoint, $status),
                        $result
                    );
                    return $result;
                } else {
                    $this->logRepository->addDebugLog(
                        sprintf('API response %s %s (status: %s)', $method, $endpoint, $status),
                        'Invalid API response.'
                    );
                    throw new LocalizedException(
                        __('Invalid API response from %1.', $this->brandRegistry->getProductName())
                    );
                }
            }
        } catch (TranslatorFailureException $e) {
            $this->logRepository->addErrorLog(
                '[api-translator-failure]',
                ['endpoint' => $endpoint, 'method' => $method, 'message' => $e->getMessage()]
            );
            return [
                'error_code' => 502,
                'http_status' => 502,
                'error_source' => 'api_translator',
                'error_message' => 'Translator failure',
            ];
        } catch (Throwable $exception) {
            return [
                'error_code' => 400,
                'error_message' => $exception->getMessage(),
            ];
        }
    }

    /**
     * Build a PSR-7 Request with auth + content headers, hand it to the brand
     * api-translator, and return the (possibly rewritten) Request.
     *
     * @throws TranslatorFailureException
     */
    private function prepareOutboundRequest(
        string $url,
        string $method,
        array $payload,
        ?int $storeId
    ): RequestInterface {
        $body = ($method === 'POST' || $method === 'PUT')
            ? (empty($payload) ? '' : (string)json_encode($payload))
            : '';
        $request = $this->requestFactory->createRequest($method, $url)
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('X-API-Key', $this->configRepository->getApiKey($storeId))
            ->withBody($this->streamFactory->createStream($body));
        try {
            return $this->apiTranslator->translateRequest($request);
        } catch (Throwable $e) {
            throw new TranslatorFailureException('translateRequest', $e);
        }
    }

    /**
     * Unpack a (translated) PSR-7 Request onto a fresh Curl client.
     */
    private function unpackToCurl(RequestInterface $request, string $method): Curl
    {
        $curl = $this->curlFactory->create();
        // Host is auto-populated by nyholm from the URI and would diverge from
        // the dispatched URL if the translator did withUri(); let libcurl derive.
        foreach ($request->getHeaders() as $name => $_) {
            if (strcasecmp($name, 'Host') === 0) {
                continue;
            }
            $curl->addHeader($name, $request->getHeaderLine($name));
        }
        $curl->setOption(CURLOPT_RETURNTRANSFER, true);
        $curl->setOption(CURLOPT_SSL_VERIFYHOST, 0);
        $curl->setOption(CURLOPT_SSL_VERIFYPEER, 0);
        $curl->setOption(CURLOPT_TIMEOUT, 60);
        if ($method === 'POST' || $method === 'PUT') {
            $reqBody = $request->getBody();
            if ($reqBody->isSeekable()) {
                $reqBody->rewind();
            }
            $curl->addHeader('Content-Length', (string)strlen((string)$reqBody));
        }
        return $curl;
    }

    /**
     * Wrap the dispatched Curl response in PSR-7 and hand it to the brand
     * api-translator. Returns the (possibly rewritten) Response.
     *
     * @throws TranslatorFailureException
     */
    private function prepareInboundResponse(Curl $curl): ResponseInterface
    {
        $response = $this->responseFactory->createResponse((int)$curl->getStatus())
            ->withBody($this->streamFactory->createStream((string)$curl->getBody()));
        foreach (($curl->getHeaders() ?: []) as $name => $value) {
            $response = $response->withHeader($name, is_array($value) ? $value : (string)$value);
        }
        try {
            return $this->apiTranslator->translateResponse($response);
        } catch (Throwable $e) {
            throw new TranslatorFailureException('translateResponse', $e);
        }
    }
}
