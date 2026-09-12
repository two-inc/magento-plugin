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

    public function __construct(array $data = [])
    {
        $this->_data = $data;
    }

    /**
     * Explicit setData, matching the real DataObject. Without it the __call
     * fallback below parses `setData` as a magic setter for a field literally
     * named "data" and the value is silently unreachable through getData().
     */
    public function setData($key, $value = null)
    {
        if (is_array($key)) {
            $this->_data = $key;
            return $this;
        }
        $this->_data[$key] = $value;
        return $this;
    }

    public function getData($key = '')
    {
        if ($key === '') {
            return $this->_data;
        }
        return $this->_data[$key] ?? null;
    }

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
