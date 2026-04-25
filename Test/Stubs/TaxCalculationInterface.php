<?php
declare(strict_types=1);

namespace Magento\Tax\Model;

/**
 * Minimal stub for Magento\Tax\Model\Calculation used in unit tests.
 *
 * Provides the two methods used by Repository::getDefaultTaxRate():
 * getRateRequest() and getRate().
 */
class Calculation
{
    /**
     * @param mixed $shippingAddress
     * @param mixed $billingAddress
     * @param mixed $customerTaxClass
     * @param mixed $storeId
     * @return \Magento\Framework\DataObject
     */
    public function getRateRequest($shippingAddress = null, $billingAddress = null, $customerTaxClass = null, $storeId = null)
    {
        return new \Magento\Framework\DataObject();
    }

    /**
     * @param \Magento\Framework\DataObject $request
     * @return float
     */
    public function getRate($request)
    {
        return 0.0;
    }
}
