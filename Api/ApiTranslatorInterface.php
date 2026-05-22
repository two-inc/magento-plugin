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
 * Hook for translating outbound HTTP between Two's native protocol and a brand
 * proxy's protocol. Default binding is
 * {@see \Two\Gateway\Model\ApiTranslator\NullApiTranslator} (passthrough); a
 * brand overlay rebinds via di.xml <preference>.
 *
 * Named ApiTranslator (not bare Translator) to avoid confusion with Magento's
 * i18n translator.
 *
 * Contract:
 *   - Implementations MUST return a non-null Request/Response.
 *   - Throwables propagate; Adapter wraps them as a 502 envelope with
 *     error_source='api_translator'.
 *   - The merchant API key arrives on the Request as X-API-Key. Implementations
 *     MUST NOT log, persist, or otherwise exfiltrate it.
 *
 * The brand owns the rest of the contract: URL rewriting, body shape, header
 * naming, idempotency-key forwarding, etc.
 */
interface ApiTranslatorInterface
{
    public function translateRequest(RequestInterface $request): RequestInterface;

    public function translateResponse(ResponseInterface $response): ResponseInterface;
}
