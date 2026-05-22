<?php
declare(strict_types=1);

namespace Magento\Framework\HTTP\Client;

/**
 * Minimal CurlFactory stub. Tests override create() to return a mock Curl.
 */
class CurlFactory
{
    public function create(): Curl
    {
        return new Curl();
    }
}
