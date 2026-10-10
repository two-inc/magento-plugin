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
 * The admin form posts the whole map as one JSON field (a rate code may hold
 * any character, and one field per row could run into max_input_vars). A
 * posted value that is not a JSON object is refused rather than saved as an
 * empty map, so a damaged post never deletes the merchant's rows.
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
     * @param mixed $value JSON string or array
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
            if (preg_match(self::KEY_PATTERN, (string)$key) && is_string($code)
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
        $value = $this->getValue();
        // Only the form's JSON object, or an emptied field, is saved: anything
        // else (a form rendered before an upgrade, a JSON list) would store an
        // empty map and delete every row.
        if (!is_string($value)
            || (trim($value) !== '' && !(json_decode($value) instanceof \stdClass))
        ) {
            throw new LocalizedException(__(
                'The tax codes for 0% lines could not be read, so nothing was saved. Reload the page and try again.'
            ));
        }
        $map = self::normalise($value);
        $this->setValue($map === [] ? '' : (string)json_encode($map));

        return parent::beforeSave();
    }
}
