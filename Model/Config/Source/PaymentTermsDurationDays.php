<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace ABN\Gateway\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * Payment Terms Duration Days Source Model
 * ABN only supports 30-day payment terms
 */
class PaymentTermsDurationDays implements OptionSourceInterface
{
    // Standard payment terms options
    public const STANDARD_OPTIONS = [7, 15, 30, 45, 60, 90];

    // End of month payment terms options
    public const END_OF_MONTH_OPTIONS = [30, 45, 60];

    /**
     * @inheritDoc
     */
    public function toOptionArray(): array
    {
        // ABN only supports 30-day payment terms
        return [
            ['value' => 30, 'label' => __('30 days')]
        ];
    }
}
