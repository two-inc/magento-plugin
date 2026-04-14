<?php
declare(strict_types=1);

namespace Magento\Tax\Api;

/**
 * Minimal stub for TaxCalculationInterface used in unit tests.
 */
interface TaxCalculationInterface
{
    /**
     * @param int $productTaxClassId
     * @param int|null $customerId
     * @param int|null $storeId
     * @return float
     */
    public function getDefaultCalculatedRate($productTaxClassId, $customerId = null, $storeId = null);
}
