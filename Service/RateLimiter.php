<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Service;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use Magento\Framework\Webapi\Exception as WebapiException;

/**
 * Per-caller request ceiling for the plugin's anonymous webapi routes.
 *
 * Every route this module registers is `<resource ref="anonymous"/>` — a
 * requirement, since a guest checkout has no token — and each one either
 * spends a merchant credential upstream or mutates the quote. Without a
 * ceiling any of them can be driven at will by anyone who can reach the
 * store.
 *
 * Fixed window rather than a rolling log: the window index is part of the
 * cache key, so an expired window is never read and needs no eviction pass.
 * Worst case a caller gets 2x the ceiling across a window boundary, which
 * is the accepted cost of not keeping per-request timestamps in cache.
 *
 * Backed by CacheInterface, the same store ApiKeyStatus memoises through —
 * so a multi-node store shares counters exactly as far as it shares that
 * cache backend, and no new infrastructure is introduced.
 */
class RateLimiter
{
    private const CACHE_KEY_PREFIX = 'two_gateway_rate_limit_';

    /** HTTP 429; absent from Webapi\Exception's own HTTP_* constants. */
    private const HTTP_TOO_MANY_REQUESTS = 429;

    public function __construct(
        private readonly CacheInterface $cache,
        private readonly RemoteAddress $remoteAddress
    ) {
    }

    /**
     * @param string $route identifier the counter is kept per, alongside the caller
     * @param int $maxRequests requests allowed per window
     * @param int $windowSeconds window length
     * @throws WebapiException when the caller is over the ceiling
     */
    public function assertWithinLimit(string $route, int $maxRequests, int $windowSeconds): void
    {
        $key = self::CACHE_KEY_PREFIX . hash(
            'sha256',
            $route . "\0" . (string)intdiv(time(), $windowSeconds) . "\0" . $this->caller()
        );

        $used = (int)$this->cache->load($key);
        if ($used >= $maxRequests) {
            throw new WebapiException(
                __('Too many requests. Please wait a moment and try again.'),
                0,
                self::HTTP_TOO_MANY_REQUESTS
            );
        }

        $this->cache->save((string)($used + 1), $key, [], $windowSeconds);
    }

    /**
     * An unresolvable remote address buckets every such caller together
     * rather than exempting them.
     */
    private function caller(): string
    {
        $ip = $this->remoteAddress->getRemoteAddress();

        return is_string($ip) && $ip !== '' ? $ip : 'unknown';
    }
}
