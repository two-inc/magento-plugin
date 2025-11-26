<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * Payment Terms Duration Days Source Model
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
        $options = [];
        foreach (self::STANDARD_OPTIONS as $days) {
            $options[] = ['value' => $days, 'label' => __('%1 days', $days)];
        }
        return $options;
    }
}
