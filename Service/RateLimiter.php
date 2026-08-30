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
use Throwable;
use Two\Gateway\Api\Config\RepositoryInterface as ConfigRepository;
use Two\Gateway\Api\Log\RepositoryInterface as LogRepository;

/**
 * Per-caller request ceiling for the plugin's anonymous webapi routes.
 *
 * Fixed window: the window index is part of the cache key, so an expired
 * window needs no eviction pass. Bounds SUSTAINED cost rather than the exact
 * count admitted in any instant — CacheInterface has no atomic increment.
 */
class RateLimiter
{
    private const CACHE_KEY_PREFIX = 'two_gateway_rate_limit_';

    /** HTTP 429; absent from Webapi\Exception's own HTTP_* constants. */
    private const HTTP_TOO_MANY_REQUESTS = 429;

    /** Caller identities the refusal roster keeps before it stops growing. */
    private const REFUSAL_ROSTER_LIMIT = 50;

    /** Below this, one caller is indistinguishable from a busy minute's first buyer. */
    private const HINT_MIN_REFUSALS = 5;

    /** Share of a window's refusals one caller must hold to read as one caller. */
    private const HINT_DOMINANT_SHARE = 80;

    /** Each report costs this many times the refusals the one before it did. */
    private const HINT_ESCALATION_FACTOR = 5;

    private bool $cacheFailureReported = false;

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

        try {
            $used = (int)$this->cache->load($key);
        } catch (Throwable $e) {
            // Availability beats denial mid-checkout, so an unreadable counter
            // admits the request.
            $this->reportCacheUnavailable('load', $e->getMessage());
            return;
        }

        if ($used >= $maxRequests) {
            $this->reportRefusal($route, $window, $windowSeconds, $maxRequests, $caller);
            throw new WebapiException(
                __('Too many requests. Please wait a moment and try again.'),
                0,
                self::HTTP_TOO_MANY_REQUESTS
            );
        }

        try {
            if ($this->cache->save((string)($used + 1), $key, [], $windowSeconds) === false) {
                $this->reportCacheUnavailable('save', 'the cache backend refused the write');
            }
        } catch (Throwable $e) {
            $this->reportCacheUnavailable('save', $e->getMessage());
        }
    }

    /** Once per request — a dead backend fails on every call. */
    private function reportCacheUnavailable(string $operation, string $reason): void
    {
        if ($this->cacheFailureReported) {
            return;
        }
        $this->cacheFailureReported = true;

        $this->logRepository->addErrorLog(
            sprintf('[rate-limit-cache-unavailable] operation=%s', $operation),
            'The checkout rate limit is not being enforced while this persists. ' . $reason
        );
    }

    /**
     * Logs the refusal with the caller concentration behind it, so an admin can
     * tell one abusive caller from every buyer arriving as one address. The
     * roster counts all window long but reports land on a geometric ladder, so
     * a refused flood cannot become its own load.
     */
    private function reportRefusal(
        string $route,
        string $window,
        int $windowSeconds,
        int $maxRequests,
        string $caller
    ): void {
        $rosterKey = self::CACHE_KEY_PREFIX . 'refusals_' . hash('sha256', $route . "\0" . $window);
        try {
            $stored = json_decode((string)$this->cache->load($rosterKey), true);
        } catch (Throwable $e) {
            $this->reportCacheUnavailable('load', $e->getMessage());
            $stored = [];
        }
        $stored = is_array($stored) ? $stored : [];

        $roster = is_array($stored['callers'] ?? null) ? $stored['callers'] : [];
        $total = (int)($stored['total'] ?? 0) + 1;

        $identity = substr(hash('sha256', $caller), 0, 16);
        if (isset($roster[$identity]) || count($roster) < self::REFUSAL_ROSTER_LIMIT) {
            $roster[$identity] = (int)($roster[$identity] ?? 0) + 1;
        }

        try {
            $this->cache->save(
                (string)json_encode(['callers' => $roster, 'total' => $total]),
                $rosterKey,
                [],
                $windowSeconds
            );
        } catch (Throwable $e) {
            $this->reportCacheUnavailable('save', $e->getMessage());
        }

        if (!self::isReportingMilestone($total)) {
            return;
        }

        $trustedProxies = $this->configRepository->getTrustedProxies();
        $distinct = count($roster);
        // Against the refusals actually attributed, not the window total: past
        // the roster limit the unattributed ones would dilute a real dominance
        // into the "ordinary load" reading, which is the one that advises
        // turning the ceiling off.
        $attributed = array_sum($roster);
        $top = $roster === [] ? 0 : max($roster);
        $share = $attributed > 0 ? (int)round(100 * $top / $attributed) : 0;
        $reported = $total >= self::HINT_MIN_REFUSALS;
        $this->logRepository->addErrorLog(
            sprintf(
                '[rate-limit-exceeded] route=%s ceiling=%d/%ds caller=%s caller_source=%s '
                . 'distinct_callers_refused=%s top_caller_share=%d%% refusals_in_window=%d '
                . 'this_caller_refusals=%d trusted_proxies=%d',
                $route,
                $maxRequests,
                $windowSeconds,
                $caller,
                $trustedProxies === [] ? 'remote_addr' : 'forwarded_for',
                $distinct >= self::REFUSAL_ROSTER_LIMIT ? $distinct . '+' : (string)$distinct,
                $share,
                $total,
                $roster[$identity] ?? 0,
                count($trustedProxies)
            ),
            $reported ? $this->concentrationHint($share, $distinct, $trustedProxies === []) : null
        );
    }

    /** Turning the ceiling off is suggested only for traffic that reads as the merchant's own buyers. */
    private function concentrationHint(int $share, int $distinct, bool $noTrustedProxies): ?string
    {
        // Share, not sole occupancy: one incidental buyer refused alongside a
        // caller at 97% would otherwise read as ordinary load mid-attack.
        if ($share >= self::HINT_DOMINANT_SHARE) {
            $scope = $distinct === 1 ? 'Every refusal' : 'Nearly every refusal';

            return $noTrustedProxies
                ? $scope . ' in this window comes from one address. If this store sits behind a reverse '
                    . 'proxy, load balancer or CDN, that is every buyer counted as a single caller and the '
                    . 'ceiling is store-wide: set Trusted proxies under General. If it does not, one caller is '
                    . 'sending sustained traffic and the ceiling is holding it.'
                : $scope . ' in this window comes from one resolved buyer address, which is one caller '
                    . 'sending sustained traffic rather than ordinary checkout load.';
        }

        return sprintf(
            'Refusals in this window are spread across %d addresses, which is the shape of ordinary '
                . 'checkout load rather than one caller. If this is genuine traffic, raise the ceiling or '
                . 'switch Disable checkout rate limiting on under Diagnostics while you investigate.',
            $distinct
        );
    }

    /** Reports land on 1, then HINT_MIN_REFUSALS scaled repeatedly by the escalation factor. */
    private static function isReportingMilestone(int $total): bool
    {
        if ($total === 1) {
            return true;
        }

        for ($at = self::HINT_MIN_REFUSALS; $at <= $total; $at *= self::HINT_ESCALATION_FACTOR) {
            if ($at === $total) {
                return true;
            }
        }

        return false;
    }

    /** Bucket callers by their /64: the smallest allocation routed to a single real-world holder. */
    private const IPV6_BUCKET_MASK_BYTES = 8;

    /**
     * The connecting peer, unless it is one of the merchant's own proxies —
     * then the buyer's address from the forwarding chain that proxy set.
     * Every unresolvable peer shares one bucket rather than escaping the ceiling.
     */
    private function caller(): string
    {
        $peer = $this->request->getServer('REMOTE_ADDR');
        $peer = is_string($peer) ? trim($peer) : '';

        $rules = $this->configRepository->getTrustedProxies();
        if ($rules === [] || $peer === '' || !self::matchesAny($peer, $rules)) {
            return $peer !== '' ? self::bucketIdentity($peer) : 'unknown';
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

        return self::bucketIdentity(is_string($client) && trim($client) !== '' ? trim($client) : $peer);
    }

    /**
     * The one chokepoint where a resolved address becomes a bucket-cache key:
     * an IPv6 address is masked to its /64 so a routed allocation (the
     * smallest real-world one) can't spin up one bucket per address. IPv4 is
     * used verbatim.
     */
    private static function bucketIdentity(string $address): string
    {
        $packed = @inet_pton($address);
        if ($packed === false || strlen($packed) !== 16) {
            return $address;
        }

        $masked = substr($packed, 0, self::IPV6_BUCKET_MASK_BYTES)
            . str_repeat("\0", 16 - self::IPV6_BUCKET_MASK_BYTES);

        return inet_ntop($masked) . '/64';
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

        // Before the cast: `(int)` turns a non-numeric suffix into 0, which
        // passes the range guard and matches every address of that family.
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

        // A width of 0 matches its whole address family, so it can only be a
        // typo. Leading zeros read as decimal, so /008 is /8.
        $bits = (int)$bits;
        $maxBits = strlen($packedSubnet) * 8;
        if ($bits < 1 || $bits > $maxBits) {
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
