<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Model\Config\Backend;

use Magento\Framework\App\Config\Value;

/**
 * Storage format for the tax code mapping: one JSON object of product tax
 * class id => Two tax code. "(none)" is stored as absence, so an unconfigured
 * merchant stores nothing.
 *
 * The codes are not checked against Two's list here: the list may be
 * unreachable at save time, and the API validates every code it receives.
 */
class TaxCodeMap extends Value
{
    private const CLASS_ID_PATTERN = '/^\d+$/';

    private const CODE_PATTERN = '/^[A-Z][A-Z0-9_]*$/';

    /**
     * The usable entries of a stored or posted value. Also the read path, so a
     * value from `config:set` or an import passes the same rules.
     *
     * @param mixed $value JSON string, or the posted array
     * @return array<string, string> tax class id => tax code
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
        foreach ($value as $classId => $code) {
            if (preg_match(self::CLASS_ID_PATTERN, (string)$classId) && is_string($code)
                && preg_match(self::CODE_PATTERN, $code)
            ) {
                $map[(string)$classId] = $code;
            }
        }
        ksort($map, SORT_NUMERIC);

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
