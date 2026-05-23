<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Api;

/**
 * Outbound HTTP call carrier. Mutable on purpose — the api-translator's job
 * is to rewrite fields in place. Brand-proxy translators MAY rewrite any field.
 */
final class ApiCall
{
    /** @var string HTTP method: GET / POST / PUT */
    public $method;

    /** @var string fully-qualified dispatch URL */
    public $url;

    /** @var array<string, string> header name → value */
    public $headers;

    /** @var string request body (already JSON-encoded for body-carrying methods) */
    public $body;

    /**
     * @param array<string, string> $headers
     */
    public function __construct(string $method, string $url, array $headers, string $body)
    {
        $this->method = $method;
        $this->url = $url;
        $this->headers = $headers;
        $this->body = $body;
    }
}
