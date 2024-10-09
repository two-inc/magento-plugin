<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace ABN\Gateway\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;
use Magento\Sales\Model\ResourceModel\Order\Status\Collection as OrderStatusCollection;

/**
 * Fulfill Order Status Options
 */
class FulfillOrderStatus implements OptionSourceInterface
{
    /**
     * @var ConfigRepository
     */
    private $orderStatusCollection;

    /**
     * @param ConfigRepository $configRepository
     */
    public function __construct(
        OrderStatusCollection $orderStatusCollection
    ) {
        $this->orderStatusCollection = $orderStatusCollection;
    }

    /**
     * Options getter
     *
     * @return array
     */
    public function toOptionArray(): array
    {
        return $this->orderStatusCollection->addStateFilter("complete")->toOptionArray();
    }
}
