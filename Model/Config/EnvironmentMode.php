<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Model\Config;

/**
 * The Environment setting's rule (TWO-26604), identical in the WooCommerce
 * and PrestaShop plugins: Production and Sandbox always, Staging only on
 * Two's infrastructure or when already stored.
 */
class EnvironmentMode
{
    /** Stored values the setting accepts, in the order the dropdown offers them. */
    public const MODES = ['production', 'sandbox', 'staging'];

    /** Fresh-install value (etc/config.xml), with or without the flag. */
    public const DEFAULT_MODE = 'sandbox';

    /**
     * Whether this shop runs on Two's own infrastructure, which alone may
     * offer Staging. Enabled only by TWO_INTERNAL_ENVIRONMENTS=1 exactly; not
     * a security gate (a staging key is needed to use staging).
     */
    public static function isInternalEnvironmentsEnabled(): bool
    {
        return getenv('TWO_INTERNAL_ENVIRONMENTS') === '1';
    }

    /**
     * The canonical mode for a stored value, or null when not recognised.
     * Trimmed, lowercased, `prod` aliased to `production`.
     *
     * @param mixed $value
     */
    public static function recognise($value): ?string
    {
        $mode = is_scalar($value) ? strtolower(trim((string)$value)) : '';
        if ($mode === 'prod') {
            $mode = 'production';
        }
        return in_array($mode, self::MODES, true) ? $mode : null;
    }

    /**
     * The mode a stored value resolves to: anything unrecognised is sandbox,
     * never production.
     *
     * @param mixed $value
     */
    public static function normalise($value): string
    {
        return self::recognise($value) ?? 'sandbox';
    }

    /**
     * Modes the dropdown offers.
     *
     * @param mixed $stored
     * @return string[]
     */
    public static function options($stored): array
    {
        if (self::isInternalEnvironmentsEnabled() || self::recognise($stored) === 'staging') {
            return self::MODES;
        }
        return ['production', 'sandbox'];
    }

    /**
     * Whether a submitted mode may be saved over the stored one.
     *
     * @param mixed $submitted
     * @param mixed $stored
     */
    public static function isSaveAllowed($submitted, $stored): bool
    {
        return is_string($submitted) && in_array($submitted, self::options($stored), true);
    }

    /**
     * Health checklist verdict: staging is healthy only on Two's
     * infrastructure, unrecognised values never.
     *
     * @param mixed $stored
     */
    public static function isHealthy($stored): bool
    {
        $mode = self::recognise($stored);
        return $mode !== null && ($mode !== 'staging' || self::isInternalEnvironmentsEnabled());
    }

    /**
     * One-time rewrite of a stored legacy value: `development` (which
     * resolved to a host that does not exist) and anything else
     * unrecognised become sandbox.
     *
     * @param mixed $stored
     */
    public static function migrate($stored): string
    {
        return self::normalise($stored);
    }
}
