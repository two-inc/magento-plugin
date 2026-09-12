<?php
/**
 * DataObject-style magic bag with Magento's real camelCase→snake_case
 * key conversion (getGrandTotal() ↔ getData('grand_total')), unlike
 * Stubs/DataObject.php whose lcfirst mapping predates it and is relied
 * on by older tests. Autoloaded via the Two\Gateway PSR-4 rule in
 * Test/bootstrap.php.
 */
declare(strict_types=1);

namespace Two\Gateway\Test\Stubs;

class UnderscoreDataObject
{
    /** @var array */
    protected $_data = [];

    public function __construct(array $data = [])
    {
        $this->_data = $data;
    }

    public function getData($key = '')
    {
        if ($key === '') {
            return $this->_data;
        }
        return $this->_data[$key] ?? null;
    }

    public function setData($key, $value = null)
    {
        if (is_array($key)) {
            $this->_data = $key;
        } else {
            $this->_data[$key] = $value;
        }
        return $this;
    }

    public function hasData($key = ''): bool
    {
        return array_key_exists($key, $this->_data);
    }

    public function __call($method, $args)
    {
        $prefix = substr($method, 0, 3);
        $key = $this->underscore(substr($method, 3));

        if ($prefix === 'set') {
            return $this->setData($key, $args[0] ?? null);
        }
        if ($prefix === 'get') {
            return $this->getData($key);
        }
        if ($prefix === 'has') {
            return $this->hasData($key);
        }

        return null;
    }

    private function underscore(string $name): string
    {
        return strtolower((string)preg_replace('/(.)([A-Z])/', '$1_$2', $name));
    }
}
