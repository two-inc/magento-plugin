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
 *
 * Requires the using class to hold a `$logRepository`.
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
        $ok = $status >= 200 && $status < 300;

        if (!$ok) {
            // These routes are anonymous, and an upstream failure body is raw
            // exception text, internal host names and merchant-record detail.
            $this->logRepository->addErrorLog(
                sprintf('[upstream-failure] status=%d', $status),
                $body
            );
            $body = [
                'error_code' => 'PROXY_REFUSED',
                'error_message' => (string)__('The service is temporarily unavailable. Please try again.'),
            ];
        }

        return (string)json_encode([
            'ok' => $ok,
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
