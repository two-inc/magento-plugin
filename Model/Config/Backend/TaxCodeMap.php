<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Model\Config\Backend;

use Magento\Framework\App\Config\Value;

/**
 * Storage format for the tax codes of 0% lines (TWO-24877, TWO-26153): one
 * JSON object of row key => Two tax code. Each product tax class has these
 * rows:
 *
 * - `<class>|exempt`: a buyer in another EU country with a VAT number;
 * - `<class>|rate:<rate code>`: one per 0% tax rate the class's rules use,
 *   keyed by the rate's own code;
 * - `<class>|none`: no tax rule for the address.
 *
 * "(none)" is stored as absence, so an unconfigured merchant stores nothing.
 * The admin form posts each row as a key and code pair, because a rate code
 * may hold characters a field name cannot.
 *
 * The codes are not checked against Two's list here: the list may be
 * unreachable at save time, and the API validates every code it receives.
 */
class TaxCodeMap extends Value
{
    private const KEY_PATTERN = '/^\d+\|(exempt|none|rate:.+)$/s';

    private const CODE_PATTERN = '/^[A-Z][A-Z0-9_]*$/';

    public static function exemptKey(int $classId): string
    {
        return $classId . '|exempt';
    }

    public static function noRuleKey(int $classId): string
    {
        return $classId . '|none';
    }

    public static function rateKey(int $classId, string $rateCode): string
    {
        return $classId . '|rate:' . $rateCode;
    }

    /**
     * The usable entries of a stored or posted value. Also the read path, so a
     * value from `config:set` or an import passes the same rules.
     *
     * @param mixed $value JSON string, or the posted array of key and code pairs
     * @return array<string, string> row key => tax code
     */
    public static function normalise($value): array
    {
        if (is_string($value)) {
            $value = trim($value) === '' ? [] : json_decode($value, true);
        }
        if (!is_array($value)) {
            return [];
        }

        $map = [];
        foreach ($value as $key => $code) {
            if (is_array($code)) {
                [$key, $code] = [$code['key'] ?? null, $code['code'] ?? null];
            }
            if (is_scalar($key) && preg_match(self::KEY_PATTERN, (string)$key) && is_string($code)
                && preg_match(self::CODE_PATTERN, $code)
            ) {
                $map[(string)$key] = $code;
            }
        }
        ksort($map, SORT_NATURAL);

        return $map;
    }

    /**
     * @inheritDoc
     */
    public function beforeSave()
    {
        $map = self::normalise($this->getValue());
        $this->setValue($map === [] ? '' : (string)json_encode($map));

        return parent::beforeSave();
    }
}
