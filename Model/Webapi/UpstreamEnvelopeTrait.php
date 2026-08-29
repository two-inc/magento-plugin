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
        $body = $result['body'] ?? [];

        if ($status === 0) {
            // Status 0 is the Adapter's transport-failure branch, whose body
            // carries the raw exception text — cURL/DNS/TLS messages naming
            // internal hosts. These routes are anonymous; the detail stays in
            // the log the Adapter already wrote.
            $body = [
                'error_code' => 'PROXY_REFUSED',
                'error_message' => (string)__('The service is temporarily unavailable. Please try again.'),
            ];
        }

        return (string)json_encode([
            'ok' => $status >= 200 && $status < 300,
            'status' => $status,
            'body' => $body,
        ]);
    }

    /**
     * A refusal this module made before calling upstream, in the same shape
     * as an upstream failure so the browser reads both through one path.
     */
    private function refusal(int $status, string $message): string
    {
        return (string)json_encode([
            'ok' => false,
            'status' => $status,
            'body' => [
                'error_code' => 'PROXY_REFUSED',
                'error_message' => $message,
            ],
        ]);
    }
}
