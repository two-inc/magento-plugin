<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace ABN\Gateway\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * Fulfill Trigger Options
 */
class FulfillTrigger implements OptionSourceInterface
{
    /**
     * Options getter
     *
     * @return array
     */
    public function toOptionArray(): array
    {
        return [
            [
                'value' => 'invoice',
                'label' => __('On Invoice'),
            ],
            [
                'value' => 'shipment',
                'label' => __('On Shipment'),
            ],
            [
                'value' => 'complete',
                'label' => __('On Completion'),
            ],
        ];
    }
}
