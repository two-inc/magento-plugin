<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Service;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use Magento\Framework\Webapi\Exception as WebapiException;
use Two\Gateway\Api\Config\RepositoryInterface as ConfigRepository;
use Two\Gateway\Api\Log\RepositoryInterface as LogRepository;

/**
 * Per-caller request ceiling for the plugin's anonymous webapi routes.
 *
 * Fixed window rather than a rolling log: the window index is part of the
 * cache key, so an expired window is never read and needs no eviction pass.
 *
 * Bounds SUSTAINED cost from one caller, not the exact number of requests
 * admitted in any instant — load→compare→save over CacheInterface is not
 * atomic and the interface offers no atomic increment.
 */
class RateLimiter
{
    private const CACHE_KEY_PREFIX = 'two_gateway_rate_limit_';

    /** HTTP 429; absent from Webapi\Exception's own HTTP_* constants. */
    private const HTTP_TOO_MANY_REQUESTS = 429;

    /** Caller identities the refusal roster keeps before it stops growing. */
    private const REFUSAL_ROSTER_LIMIT = 50;

    public function __construct(
        private readonly CacheInterface $cache,
        private readonly HttpRequest $request,
        private readonly ConfigRepository $configRepository,
        private readonly LogRepository $logRepository
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
        if ($this->configRepository->isRateLimitDisabled()) {
            return;
        }

        $window = (string)intdiv(time(), $windowSeconds);
        $caller = $this->caller();
        $key = self::CACHE_KEY_PREFIX . hash('sha256', $route . "\0" . $window . "\0" . $caller);

        $used = (int)$this->cache->load($key);
        if ($used >= $maxRequests) {
            $this->reportRefusal($route, $window, $windowSeconds, $maxRequests, $caller);
            throw new WebapiException(
                __('Too many requests. Please wait a moment and try again.'),
                0,
                self::HTTP_TOO_MANY_REQUESTS
            );
        }

        $this->cache->save((string)($used + 1), $key, [], $windowSeconds);
    }

    /**
     * Records the refusal against the route's window and logs it with the
     * caller concentration behind it, so an admin can tell one abusive
     * caller from every buyer arriving as one address.
     */
    private function reportRefusal(
        string $route,
        string $window,
        int $windowSeconds,
        int $maxRequests,
        string $caller
    ): void {
        $rosterKey = self::CACHE_KEY_PREFIX . 'refusals_' . hash('sha256', $route . "\0" . $window);
        $roster = json_decode((string)$this->cache->load($rosterKey), true);
        $roster = is_array($roster) ? $roster : [];

        $identity = substr(hash('sha256', $caller), 0, 16);
        if (isset($roster[$identity]) || count($roster) < self::REFUSAL_ROSTER_LIMIT) {
            $roster[$identity] = (int)($roster[$identity] ?? 0) + 1;
            $this->cache->save((string)json_encode($roster), $rosterKey, [], $windowSeconds);
        }

        $trustedProxies = $this->configRepository->getTrustedProxies();
        $this->logRepository->addErrorLog(
            sprintf(
                '[rate-limit-exceeded] route=%s ceiling=%d/%ds caller=%s caller_source=%s '
                . 'distinct_callers_refused=%d refusals_in_window=%d this_caller_refusals=%d trusted_proxies=%d',
                $route,
                $maxRequests,
                $windowSeconds,
                $caller,
                $trustedProxies === [] ? 'remote_addr' : 'forwarded_for',
                count($roster),
                array_sum($roster),
                $roster[$identity] ?? 0,
                count($trustedProxies)
            ),
            count($roster) === 1 && $trustedProxies === []
                ? 'All refusals in this window come from one address and no trusted proxies are configured. '
                    . 'If this store sits behind a reverse proxy, load balancer or CDN, every buyer reaches it as '
                    . 'that one address and the ceiling is store-wide: set Trusted proxies under General, or switch '
                    . 'Disable checkout rate limiting on under Diagnostics while you do.'
                : null
        );
    }

    /**
     * The connecting peer, unless it is one of the merchant's own proxies —
     * then the buyer's address from the forwarding chain that proxy set.
     *
     * Resolution is Magento's own RemoteAddress, handed the trusted set so
     * its filter can drop the proxy hops; with none configured the peer is
     * the only value not supplied by the caller, and every unresolvable
     * peer shares one bucket rather than escaping the ceiling.
     */
    private function caller(): string
    {
        $peer = $this->request->getServer('REMOTE_ADDR');
        $peer = is_string($peer) ? trim($peer) : '';

        $rules = $this->configRepository->getTrustedProxies();
        if ($rules === [] || $peer === '' || !self::matchesAny($peer, $rules)) {
            return $peer !== '' ? $peer : 'unknown';
        }

        $forwarded = $this->request->getServer('HTTP_X_FORWARDED_FOR');
        $chain = array_map('trim', explode(',', is_string($forwarded) ? $forwarded : ''));
        $trusted = array_values(array_filter(
            array_unique(array_merge([$peer], $chain)),
            static fn($address) => $address !== '' && self::matchesAny($address, $rules)
        ));

        // Constructed rather than injected: both arguments are per-request,
        // and the generated factory exists only after setup:di:compile.
        $client = (new RemoteAddress($this->request, ['HTTP_X_FORWARDED_FOR'], $trusted))->getRemoteAddress();

        return is_string($client) && trim($client) !== '' ? trim($client) : $peer;
    }

    /**
     * @param string[] $rules IPs or CIDR ranges
     */
    private static function matchesAny(string $address, array $rules): bool
    {
        foreach ($rules as $rule) {
            if (self::matches($address, $rule)) {
                return true;
            }
        }

        return false;
    }

    private static function matches(string $address, string $rule): bool
    {
        if (strpos($rule, '/') === false) {
            return strcasecmp($address, $rule) === 0;
        }

        [$subnet, $bits] = explode('/', $rule, 2);
        $packedAddress = @inet_pton($address);
        $packedSubnet = @inet_pton($subnet);
        if ($packedAddress === false || $packedSubnet === false
            || strlen($packedAddress) !== strlen($packedSubnet)
        ) {
            return false;
        }

        $bits = (int)$bits;
        $maxBits = strlen($packedSubnet) * 8;
        if ($bits < 0 || $bits > $maxBits) {
            return false;
        }

        $wholeBytes = intdiv($bits, 8);
        if ($wholeBytes > 0 && strncmp($packedAddress, $packedSubnet, $wholeBytes) !== 0) {
            return false;
        }

        $remainder = $bits % 8;
        if ($remainder === 0) {
            return true;
        }

        $mask = ~((1 << (8 - $remainder)) - 1) & 0xFF;

        return (ord($packedAddress[$wholeBytes]) & $mask) === (ord($packedSubnet[$wholeBytes]) & $mask);
    }
}
