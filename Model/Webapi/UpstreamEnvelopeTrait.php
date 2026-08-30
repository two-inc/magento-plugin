<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Model\Webapi;

/**
 * Wraps an Adapter result so a proxied call keeps the pass/fail distinction the
 * browser used to read off its own request's HTTP status — the proxy answers
 * 200 either way, and a webapi fault would discard the upstream error body.
 *
 * Requires the using class to hold a `$logRepository` and a `$checkoutSession`.
 */
trait UpstreamEnvelopeTrait
{
    /** The only upstream 4xx keys a buyer can act on; the rest of the body is internal. */
    private const RELAYED_4XX_FIELDS = ['error_code', 'error_message', 'error_details', 'error_json'];

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
            $relayed = $status >= 400 && $status < 500 && is_array($body)
                ? array_intersect_key($body, array_flip(self::RELAYED_4XX_FIELDS))
                : [];
            $body = $relayed !== [] ? $relayed : [
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
     * Read off the quote, not the request: these routes are reached at
     * /rest/V1/... with no store code, so the request resolves to the default
     * store. Null falls the call back to the default scope.
     */
    private function quoteStoreId(): ?int
    {
        try {
            $storeId = (int)$this->checkoutSession->getQuote()->getStoreId();
        } catch (\Throwable $e) {
            return null;
        }

        return $storeId > 0 ? $storeId : null;
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
