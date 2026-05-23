<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Api;

/**
 * Inbound HTTP response carrier. Mutable; the api-translator may rewrite any field.
 */
final class ApiResult
{
    /** @var int HTTP status code */
    public $status;

    /** @var array<string, string|string[]> response header name → value(s) */
    public $headers;

    /** @var string response body */
    public $body;

    /**
     * @param array<string, string|string[]> $headers
     */
    public function __construct(int $status, array $headers, string $body)
    {
        $this->status = $status;
        $this->headers = $headers;
        $this->body = $body;
    }
}
