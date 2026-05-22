<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Api;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Outbound HTTP translator between Two-native protocol and a brand-proxy protocol.
 *
 * Default binding is {@see \Two\Gateway\Model\Translator\NullTranslator} (passthrough).
 * A brand overlay module swaps the binding via di.xml <preference>. See docs/proxy-translator-design.md.
 *
 * Implementations SHOULD `use PassthroughTrait` to remain forward-compatible with
 * minor-version additions to this interface. Direct `implements TranslatorInterface`
 * without the trait is unsupported: new methods added in minor versions will cause
 * a fatal "Class contains abstract method" error at class-load.
 */
interface TranslatorInterface
{
    /** Internal header carrying the {@see Operation}::* tag. Adapter strips it post-translation. */
    public const OP_HEADER = 'X-Two-Operation';

    /** Headers whose name MUST survive translation (value MAY be rewritten). */
    public const PRESERVE_HEADERS = ['X-Request-ID', 'X-Idempotency-Key'];

    /** Prefix-matched header preserve list. */
    public const PRESERVE_HEADER_PREFIXES = ['X-Trace-'];

    /**
     * Response headers required intact for header-reading operations
     * ({@see Operation::DELEGATION_TOKEN}, {@see Operation::AUTOFILL_TOKEN}).
     * Names match what Adapter currently consumes via the token-endpoint branch.
     */
    public const TOKEN_RESPONSE_HEADERS = [
        'two-delegated-authority-token',
    ];

    /**
     * Translate an outbound Request from Two-native to brand-proxy protocol.
     *
     * Contract:
     *   - MUST return a non-null Request.
     *   - MUST be idempotent.
     *   - MUST leave the name of every header in PRESERVE_HEADERS (and every header
     *     with a name starting with any PRESERVE_HEADER_PREFIXES prefix) reachable
     *     in the post-translation Request. Adapter restores any missing entry from
     *     the pre-translation Request and logs at WARN.
     *   - MUST NOT set Content-Length. Adapter strips and recomputes it.
     *   - SHOULD NOT rewrite OP_HEADER value; Adapter strips OP_HEADER post-translation.
     *   - SHOULD rewrite status via withStatus() rather than throwing for control flow.
     *   - Thrown \Throwable → Adapter returns 502 envelope with error_source='translator'.
     *
     * Security: the merchant API key arrives on the pre-translation Request as the
     * X-API-Key header. Translators MUST NOT log, persist, or otherwise exfiltrate
     * the value. The Adapter sets it pre-translation only so brand proxies can
     * rewrite the header NAME (e.g. to Authorization: Bearer); a faithful translator
     * preserves or renames, never extracts and stores.
     */
    public function translateRequest(RequestInterface $request): RequestInterface;

    /**
     * Translate an inbound Response from brand-proxy to Two-native protocol.
     *
     * Contract:
     *   - MUST return a non-null Response.
     *   - For header-reading operations, MUST preserve every entry in
     *     TOKEN_RESPONSE_HEADERS by name. Missing any → translator failure (502).
     *   - MUST NOT return an empty body for body-reading operations except status 204
     *     (empty := trim((string)$body) === '').
     *   - MUST normalise body shape back to Two-native JSON for body-reading operations.
     *   - Response body, if rewritten via withBody(), MUST be a seekable stream
     *     (Adapter rewinds and casts to string for downstream parsing).
     *   - Thrown \Throwable → Adapter returns 502 envelope with error_source='translator'.
     */
    public function translateResponse(ResponseInterface $response): ResponseInterface;
}
