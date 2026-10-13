<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Test\E2E\Http;

use Two\Gateway\Service\Api\Adapter;

/**
 * The staging merchant key and test buyer the staging API tests need
 * (TWO-26325). Without them a test skips locally, but fails when
 * TWO_E2E_REQUIRED is set, as CI sets it: a skipped canary reports nothing.
 */
trait StagingEnvTrait
{
    private function stagingApiKey(): string
    {
        $apiKey = (string)getenv('TWO_API_KEY');
        $this->requireStagingEnv($apiKey !== '', 'TWO_API_KEY', 'a staging test merchant key');

        return $apiKey;
    }

    /**
     * @return array the buyer, as JSON with `buyer`, `billing_address`, `shipping_address` and `currency`
     */
    private function stagingBuyer(): array
    {
        $buyer = json_decode((string)getenv('TWO_E2E_BUYER'), true);
        $this->requireStagingEnv(is_array($buyer), 'TWO_E2E_BUYER', 'a staging test buyer the merchant can sell to');

        return (array)$buyer;
    }

    private function stagingBaseUrl(): string
    {
        return getenv('TWO_API_BASE_URL') ?: 'https://api.staging.two.inc';
    }

    /**
     * A call to staging with its HTTP status, which execute() drops: status 0
     * means no HTTP exchange completed.
     *
     * @return array{0: int, 1: array}
     */
    private function sendToStaging(Adapter $adapter, string $endpoint, array $payload, string $method): array
    {
        $response = $adapter->executeWithStatus($endpoint, $payload, $method);

        return [(int)$response['status'], (array)$response['body']];
    }

    private function requireStagingEnv(bool $present, string $name, string $what): void
    {
        if ($present) {
            return;
        }
        $message = sprintf('%s is not set: this test needs %s.', $name, $what);
        if (getenv('TWO_E2E_REQUIRED')) {
            $this->fail($message . ' TWO_E2E_REQUIRED is set, so this fails rather than skips.');
        }
        $this->markTestSkipped($message);
    }
}
