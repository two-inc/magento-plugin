<?php
declare(strict_types=1);

namespace Magento\Framework\HTTP\Client;

/**
 * Minimal CurlFactory stub for unit tests. Magento auto-generates the real
 * factory at setup:di:compile; PHPUnit needs the create() method declared
 * so it can be mocked.
 */
class CurlFactory
{
    public function create(): Curl
    {
        return new Curl();
    }
}
