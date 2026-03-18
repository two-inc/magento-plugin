<?php
declare(strict_types=1);

namespace Magento\Bundle\Model\Product;

/**
 * Minimal Price stub — provides the pricing-type constants
 * used by Service\Order::shouldSkip().
 */
class Price
{
    public const PRICE_TYPE_FIXED = 0;
    public const PRICE_TYPE_DYNAMIC = 1;
}
