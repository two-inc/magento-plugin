<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Service\Api;

use RuntimeException;
use Throwable;

/**
 * Internal signal: Adapter caught a Throwable from a brand-translator call.
 * Carries the original cause for logging; Adapter converts to a 502 envelope.
 */
final class TranslatorFailureException extends RuntimeException
{
    public function __construct(string $phase, Throwable $previous)
    {
        parent::__construct(
            sprintf('api-translator failed in %s: %s', $phase, $previous->getMessage()),
            0,
            $previous
        );
    }
}
