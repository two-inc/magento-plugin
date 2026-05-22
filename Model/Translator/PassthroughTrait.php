<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Model\Translator;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Forward-compatible default implementations for {@see \Two\Gateway\Api\TranslatorInterface}.
 *
 * Brand translators `use PassthroughTrait;` so that minor-version additions to the
 * interface ship with defaults and do not break existing brand code at class-load.
 *
 * Magento <type> plugin codegen intercepts trait-defaulted methods the same as
 * hand-written ones; brand authors writing before/around/after plugins should expect
 * their plugins to fire against these defaults.
 */
trait PassthroughTrait
{
    public function translateRequest(RequestInterface $request): RequestInterface
    {
        return $request;
    }

    public function translateResponse(ResponseInterface $response): ResponseInterface
    {
        return $response;
    }
}
