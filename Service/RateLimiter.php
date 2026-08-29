<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Service;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
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
 *
 * What the ceiling does and does not guarantee: load→compare→save over
 * CacheInterface is not atomic and the interface offers no atomic
 * increment, so concurrent requests can read the same count and all pass.
 * It bounds SUSTAINED cost from one caller, not the exact number of
 * requests admitted in any instant.
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
        private readonly HttpRequest $request
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
     * The connecting peer, NOT RemoteAddress::getRemoteAddress(): stock
     * Magento trusts X-Forwarded-For with no proxy allow-list, so that
     * reader hands back a client-supplied value. Keying on it would let a
     * caller mint a fresh bucket per request by rotating the header, and
     * every rotation would also add a never-colliding cache entry.
     *
     * An unresolvable peer buckets every such caller together rather than
     * exempting them.
     */
    private function caller(): string
    {
        $ip = $this->request->getServer('REMOTE_ADDR');

        return is_string($ip) && $ip !== '' ? $ip : 'unknown';
    }
}
