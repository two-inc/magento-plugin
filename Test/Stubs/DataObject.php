<?php
declare(strict_types=1);

namespace Magento\Framework;

/**
 * Minimal DataObject stub for unit tests.
 *
 * Supports get/set via __call magic, matching Magento's DataObject.
 */
class DataObject
{
    /** @var array */
    protected $_data = [];

    public function __call($method, $args)
    {
        $prefix = substr($method, 0, 3);
        $key = lcfirst(substr($method, 3));

        if ($prefix === 'set') {
            $this->_data[$key] = $args[0] ?? null;
            return $this;
        }
        if ($prefix === 'get') {
            return $this->_data[$key] ?? null;
        }

        return null;
    }
}
