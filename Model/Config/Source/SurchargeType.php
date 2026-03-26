<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * Surcharge Type Source Model
 */
class SurchargeType implements OptionSourceInterface
{
    public const NONE = 'none';
    public const PERCENTAGE = 'percentage';
    public const FIXED = 'fixed';
    public const FIXED_AND_PERCENTAGE = 'fixed_and_percentage';

    /**
     * @inheritDoc
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => self::NONE, 'label' => __('No')],
            ['value' => self::PERCENTAGE, 'label' => __('Percentage')],
            ['value' => self::FIXED, 'label' => __('Fixed fee')],
            ['value' => self::FIXED_AND_PERCENTAGE, 'label' => __('Fixed fee and percentage')],
        ];
    }
}
