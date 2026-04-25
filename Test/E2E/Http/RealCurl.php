<?php
declare(strict_types=1);

namespace Two\Gateway\Test\E2E\Http;

use Magento\Framework\HTTP\Client\Curl;

/**
 * Real HTTP implementation of the Curl interface for E2E tests.
 * Mirrors the interface of the Magento Curl stub so it can be injected
 * into Service\Api\Adapter without modification.
 */
class RealCurl extends Curl
{
    private array $headers = [];
    private array $options = [];
    private string $body = '';
    private int $status = 0;
    private array $responseHeaders = [];

    public function addHeader(string $name, $value): void
    {
        $this->headers[] = "$name: $value";
    }

    public function setOption(int $option, $value): void
    {
        $this->options[$option] = $value;
    }

    public function post(string $url, $params): void
    {
        $this->doRequest($url, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $params,
        ]);
    }

    public function get(string $url): void
    {
        $this->doRequest($url, [
            CURLOPT_HTTPGET => true,
        ]);
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    public function getHeaders(): array
    {
        return $this->responseHeaders;
    }

    private function doRequest(string $url, array $extraOptions): void
    {
        $ch = curl_init($url);

        $responseHeaders = [];
        $curlOptions = $this->options + $extraOptions + [
            CURLOPT_HTTPHEADER => $this->headers,
            CURLOPT_HEADERFUNCTION => function ($ch, $header) use (&$responseHeaders) {
                $len = strlen($header);
                $parts = explode(':', $header, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return $len;
            },
        ];

        curl_setopt_array($ch, $curlOptions);

        $this->body = (string)(curl_exec($ch) ?: '');
        $this->status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $this->responseHeaders = $responseHeaders;

        curl_close($ch);
        $this->headers = [];
        $this->options = [];
    }
}
