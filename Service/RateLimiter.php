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

    /**
     * Refusals a route's window needs before the log commits to reading the
     * concentration behind them. Below it, one caller is indistinguishable
     * from the first buyer of a busy minute.
     */
    private const HINT_MIN_REFUSALS = 5;

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
     *
     * A refused flood must not become its own load: the roster stops being
     * written and nothing further is logged once the window has reported,
     * bounding a window of any size to two log lines and a handful of cache
     * writes per route.
     */
    private function reportRefusal(
        string $route,
        string $window,
        int $windowSeconds,
        int $maxRequests,
        string $caller
    ): void {
        $rosterKey = self::CACHE_KEY_PREFIX . 'refusals_' . hash('sha256', $route . "\0" . $window);
        $stored = json_decode((string)$this->cache->load($rosterKey), true);
        $stored = is_array($stored) ? $stored : [];
        if (!empty($stored['reported'])) {
            return;
        }

        $roster = is_array($stored['callers'] ?? null) ? $stored['callers'] : [];
        $total = (int)($stored['total'] ?? 0) + 1;

        $identity = substr(hash('sha256', $caller), 0, 16);
        if (isset($roster[$identity]) || count($roster) < self::REFUSAL_ROSTER_LIMIT) {
            $roster[$identity] = (int)($roster[$identity] ?? 0) + 1;
        }

        $reported = $total >= self::HINT_MIN_REFUSALS;
        $this->cache->save(
            (string)json_encode(['callers' => $roster, 'total' => $total, 'reported' => $reported]),
            $rosterKey,
            [],
            $windowSeconds
        );

        if ($total !== 1 && !$reported) {
            return;
        }

        $trustedProxies = $this->configRepository->getTrustedProxies();
        $distinct = count($roster);
        $this->logRepository->addErrorLog(
            sprintf(
                '[rate-limit-exceeded] route=%s ceiling=%d/%ds caller=%s caller_source=%s '
                . 'distinct_callers_refused=%s refusals_in_window=%d this_caller_refusals=%d trusted_proxies=%d',
                $route,
                $maxRequests,
                $windowSeconds,
                $caller,
                $trustedProxies === [] ? 'remote_addr' : 'forwarded_for',
                $distinct >= self::REFUSAL_ROSTER_LIMIT ? $distinct . '+' : (string)$distinct,
                $total,
                $roster[$identity] ?? 0,
                count($trustedProxies)
            ),
            $reported ? $this->concentrationHint($distinct, $trustedProxies === []) : null
        );
    }

    /**
     * Reading of what the window's refusals look like, once there are enough
     * of them to have a shape.
     *
     * Turning the ceiling off is only ever suggested for the shape that reads
     * as the merchant's own buyers — advising it against what looks like an
     * attack would hand the attacker the store.
     */
    private function concentrationHint(int $distinct, bool $noTrustedProxies): ?string
    {
        if ($distinct === 1) {
            return $noTrustedProxies
                ? 'Every refusal in this window comes from one address. If this store sits behind a reverse '
                    . 'proxy, load balancer or CDN, that is every buyer counted as a single caller and the '
                    . 'ceiling is store-wide: set Trusted proxies under General. If it does not, one caller is '
                    . 'sending sustained traffic and the ceiling is holding it.'
                : 'Every refusal in this window comes from one resolved buyer address, which is one caller '
                    . 'sending sustained traffic rather than ordinary checkout load.';
        }

        return sprintf(
            'Refusals in this window are spread across %d addresses, which is the shape of ordinary '
                . 'checkout load rather than one caller. If this is genuine traffic, raise the ceiling or '
                . 'switch Disable checkout rate limiting on under Diagnostics while you investigate.',
            $distinct
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
            // Packed, not textual: 2001:0db8::1 and 2001:db8::1 are the same
            // address, and a stored rule can reach here unnormalised via
            // config:set or an import.
            $packedRule = @inet_pton($rule);

            return $packedRule !== false && @inet_pton($address) === $packedRule;
        }

        [$subnet, $bits] = explode('/', $rule, 2);

        // Before the cast: `(int)` turns an empty or non-numeric suffix into 0,
        // which passes the range guard and then matches every address of that
        // family — one typo in the trusted list would trust the whole internet.
        if (preg_match('/^\d+$/', $bits) !== 1) {
            return false;
        }

        $packedAddress = @inet_pton($address);
        $packedSubnet = @inet_pton($subnet);
        if ($packedAddress === false || $packedSubnet === false
            || strlen($packedAddress) !== strlen($packedSubnet)
        ) {
            return false;
        }

        $bits = (int)$bits;
        $maxBits = strlen($packedSubnet) * 8;
        if ($bits > $maxBits) {
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
