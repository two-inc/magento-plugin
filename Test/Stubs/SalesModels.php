<?php
/**
 * Stubs for the Magento sales models the surcharge Total collectors operate
 * on (Invoice, Creditmemo, Order). The collectors type-hint the concrete
 * Magento classes, so unit tests need instances of those exact classes — the
 * bootstrap catch-all autoloader only produces empty, method-less classes.
 *
 * These stubs reproduce just enough of Magento\Framework\DataObject to be
 * faithful: a backing data array plus get/set/has/uns magic with the same
 * CamelCase -> snake_case key conversion Magento uses, so that
 * getGrandTotal() and getData('grand_total') address the same slot. That
 * fidelity matters for the Creditmemo collector, which mixes magic getters
 * with explicit getData('two_surcharge_amount')/hasData() calls.
 */
declare(strict_types=1);

namespace Two\Gateway\Test\Stubs;

/**
 * Minimal faithful re-implementation of Magento\Framework\DataObject's
 * data access — enough for the surcharge collectors under test.
 */
abstract class AbstractSalesModelStub
{
    /** @var array */
    protected $_data = [];

    public function setData($key, $value = null): self
    {
        $this->_data[$key] = $value;
        return $this;
    }

    public function getData($key = '', $index = null)
    {
        if ($key === '') {
            return $this->_data;
        }
        return $this->_data[$key] ?? null;
    }

    public function hasData($key = ''): bool
    {
        if ($key === '') {
            return !empty($this->_data);
        }
        return array_key_exists($key, $this->_data);
    }

    public function __call($method, $args)
    {
        $prefix = substr($method, 0, 3);
        $key = $this->_underscore(substr($method, 3));
        switch ($prefix) {
            case 'get':
                return $this->_data[$key] ?? null;
            case 'set':
                $this->_data[$key] = $args[0] ?? null;
                return $this;
            case 'has':
                return array_key_exists($key, $this->_data);
            case 'uns':
                unset($this->_data[$key]);
                return $this;
        }
        return null;
    }

    /**
     * CamelCase -> snake_case, matching Magento\Framework\DataObject.
     */
    protected function _underscore(string $name): string
    {
        return strtolower(preg_replace('/(.)([A-Z])/', '$1_$2', $name));
    }
}

namespace Magento\Sales\Model\Order;

use Two\Gateway\Test\Stubs\AbstractSalesModelStub;

if (!class_exists(Invoice::class, false)) {
    class Invoice extends AbstractSalesModelStub
    {
        private $order;

        public function setOrder($order): self
        {
            $this->order = $order;
            return $this;
        }

        public function getOrder()
        {
            return $this->order;
        }
    }
}

if (!class_exists(Creditmemo::class, false)) {
    class Creditmemo extends AbstractSalesModelStub
    {
        private $order;

        public function setOrder($order): self
        {
            $this->order = $order;
            return $this;
        }

        public function getOrder()
        {
            return $this->order;
        }
    }
}

namespace Magento\Sales\Model;

use Two\Gateway\Test\Stubs\AbstractSalesModelStub;

if (!class_exists(Order::class, false)) {
    // The order is untyped in the collectors; a plain data bag suffices.
    class Order extends AbstractSalesModelStub
    {
    }
}
