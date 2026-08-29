<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Model\Webapi;

/**
 * Wraps a Service\Api\Adapter result so a proxied call keeps the pass/fail
 * distinction the browser used to read off the HTTP status of its own direct
 * request. The proxy itself answers 200 either way; a Magento webapi fault
 * would discard the upstream error body these callers render from.
 */
trait UpstreamEnvelopeTrait
{
    /**
     * @param array{status: int, body: array<string,mixed>} $result from Adapter::executeWithStatus()
     */
    private function envelope(array $result): string
    {
        $status = (int)($result['status'] ?? 0);

        return (string)json_encode([
            'ok' => $status >= 200 && $status < 300,
            'status' => $status,
            'body' => $result['body'] ?? [],
        ]);
    }
}
