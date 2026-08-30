<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Model\Config\Backend;

use Magento\Framework\App\Config\Value;
use Magento\Framework\Exception\LocalizedException;

/**
 * Entry gate for the trusted-proxy list the checkout rate limit resolves
 * callers through.
 *
 * A malformed entry is refused rather than stored: `Service\RateLimiter`
 * treats anything it cannot parse as "does not match", so a typo silently
 * retires the proxy it was meant to name and every buyer behind that proxy
 * is counted as one caller again.
 *
 * Addresses are stored packed-canonical, so an entry written as
 * 2001:0db8::1 still matches the 2001:db8::1 the server reports.
 */
class TrustedProxies extends Value
{
    /**
     * @inheritDoc
     * @throws LocalizedException
     */
    public function beforeSave()
    {
        $entries = preg_split('/[\s,;]+/', (string)$this->getValue()) ?: [];
        $normalised = [];

        foreach ($entries as $entry) {
            if ($entry === '') {
                continue;
            }

            $canonical = self::canonicalise($entry);
            if ($canonical === null) {
                throw new LocalizedException(
                    __('Trusted proxies: "%1" is not a valid IP address or CIDR range.', $entry)
                );
            }

            $normalised[$canonical] = true;
        }

        $this->setValue(implode("\n", array_keys($normalised)));

        return parent::beforeSave();
    }

    /**
     * @return string|null null when the entry is not an address or range
     */
    private static function canonicalise(string $entry): ?string
    {
        if (strpos($entry, '/') === false) {
            $packed = @inet_pton($entry);

            return $packed === false ? null : (string)@inet_ntop($packed);
        }

        [$subnet, $bits] = explode('/', $entry, 2);
        $packed = @inet_pton($subnet);
        if ($packed === false || preg_match('/^\d+$/', $bits) !== 1) {
            return null;
        }

        if ((int)$bits > strlen($packed) * 8) {
            return null;
        }

        return @inet_ntop($packed) . '/' . (int)$bits;
    }
}
