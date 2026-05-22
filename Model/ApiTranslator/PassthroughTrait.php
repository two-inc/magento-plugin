<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Model\ApiTranslator;

use Two\Gateway\Api\ApiCall;
use Two\Gateway\Api\ApiResult;

/**
 * Forward-compatible default implementations for
 * {@see \Two\Gateway\Api\ApiTranslatorInterface}. Brand api-translators
 * `use PassthroughTrait;` so minor-version additions to the interface
 * ship with passthrough defaults.
 */
trait PassthroughTrait
{
    public function translateRequest(ApiCall $call): ApiCall
    {
        return $call;
    }

    public function translateResponse(ApiResult $result): ApiResult
    {
        return $result;
    }
}
