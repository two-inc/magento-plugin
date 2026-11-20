<?php
declare(strict_types=1);

namespace Magento\Tax\Model;

/**
 * Minimal stub for Magento\Tax\Model\Calculation used in unit tests.
 *
 * Provides the methods the plugin calls: getRateRequest(), getRate() and
 * getAppliedRates().
 */
class Calculation
{
    /**
     * @param mixed $shippingAddress
     * @param mixed $billingAddress
     * @param mixed $customerTaxClass
     * @param mixed $storeId
     * @param mixed $customerId
     * @return \Magento\Framework\DataObject
     */
    public function getRateRequest(
        $shippingAddress = null,
        $billingAddress = null,
        $customerTaxClass = null,
        $storeId = null,
        $customerId = null
    )
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

    /**
     * @param \Magento\Framework\DataObject $request
     * @return array
     */
    public function getAppliedRates($request)
    {
        return [];
    }
}
