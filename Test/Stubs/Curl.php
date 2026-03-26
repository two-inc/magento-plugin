<?php
declare(strict_types=1);

namespace Magento\Framework\HTTP\Client;

/**
 * Minimal Curl stub for unit tests.
 *
 * The catch-all autoloader creates an empty class, but PHPUnit can't
 * mock methods that don't exist. This stub declares the methods used
 * by Service\Api\Adapter.
 */
class Curl
{
    public function addHeader(string $name, $value): void
    {
    }

    public function setOption(int $option, $value): void
    {
    }

    public function post(string $url, $params): void
    {
    }

    public function get(string $url): void
    {
    }

    public function getBody(): string
    {
        return '';
    }

    public function getStatus(): int
    {
        return 0;
    }

    public function getHeaders(): array
    {
        return [];
    }
}
