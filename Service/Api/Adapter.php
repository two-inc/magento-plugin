<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace ABN\Gateway\Service\Api;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\HTTP\Client\Curl;
use Throwable;
use ABN\Gateway\Api\Config\RepositoryInterface as ConfigRepository;
use ABN\Gateway\Api\Log\RepositoryInterface as LogRepository;
use ABN\Gateway\Api\Webapi\SoleTraderInterface;

/**
 * Api Adapter
 */
class Adapter
{
    /**
     * @var ConfigRepository
     */
    private $configRepository;

    /**
     * @var Curl
     */
    private $curlClient;

    /**
     * @var LogRepository
     */
    private $logRepository;

    /**
     * Adapter constructor.
     *
     * @param ConfigRepository $configRepository
     * @param Curl $curlClient
     * @param LogRepository $logRepository
     */
    public function __construct(
        ConfigRepository $configRepository,
        Curl $curlClient,
        LogRepository $logRepository
    ) {
        $this->configRepository = $configRepository;
        $this->curlClient = $curlClient;
        $this->logRepository = $logRepository;
    }

    /**
     * Send request to api
     *
     * @param string $endpoint
     * @param array $payload
     * @param string $method
     * @return array
     */
    public function execute(string $endpoint, array $payload = [], string $method = 'POST'): array
    {
        try {
            $this->logRepository->addDebugLog(sprintf('API call: %s %s', $method, $endpoint), $payload);
            $url = $this->configRepository->addVersionDataInURL(
                sprintf('%s%s', $this->configRepository->getCheckoutApiUrl(), $endpoint)
            );
            $this->curlClient->addHeader("Content-Type", "application/json");
            $this->curlClient->addHeader("X-API-Key", $this->configRepository->getApiKey());
            $this->curlClient->setOption(CURLOPT_RETURNTRANSFER, true);
            $this->curlClient->setOption(CURLOPT_SSL_VERIFYHOST, 0);
            $this->curlClient->setOption(CURLOPT_SSL_VERIFYPEER, 0);
            $this->curlClient->setOption(CURLOPT_TIMEOUT, 60);

            if ($method == "POST" || $method == "PUT") {
                $params = empty($payload) ? '' : json_encode($payload);
                $this->curlClient->addHeader("Content-Length", strlen($params));
                $this->curlClient->post($url, $params);
            } else {
                $this->curlClient->setOption(CURLOPT_FOLLOWLOCATION, true);
                $this->curlClient->get($url);
            }

            $body = trim($this->curlClient->getBody());
            if (in_array($this->curlClient->getStatus(), [200, 201, 202])) {
                $result = [];
                if ((!$body || $body === '""')) {
                    if (in_array($endpoint, [
                            SoleTraderInterface::DELEGATION_TOKEN_ENDPOINT,
                            SoleTraderInterface::AUTOFILL_TOKEN_ENDPOINT])) {
                        $result = $this->curlClient->getHeaders();
                        foreach ($result as $key => $value) {
                            $result[strtolower($key)] = $value;
                        }
                    }
                } else {
                    $result = json_decode($body, true);
                }
                $this->logRepository->addDebugLog(
                    sprintf('API response %s %s (status: %s)', $method, $endpoint, $this->curlClient->getStatus()),
                    $result
                );
                return $result;
            } else {
                if ($body) {
                    $result = json_decode($body, true) ?: [];
                    $this->logRepository->addDebugLog(
                        sprintf('API response %s %s (status: %s)', $method, $endpoint, $this->curlClient->getStatus()),
                        $result
                    );
                    return $result;
                } else {
                    $this->logRepository->addDebugLog(
                        sprintf('API response %s %s (status: %s)', $method, $endpoint, $this->curlClient->getStatus()),
                        'Invalid API response.'
                    );
                    throw new LocalizedException(
                        __('Invalid API response from %1.', $this->configRepository::PRODUCT_NAME)
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
}
