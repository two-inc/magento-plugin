<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * Direction the buyer surcharge line item is snapped to the rounding step.
 *
 * NONE leaves the amount at the standard two-decimal precision (no rounding
 * block is sent to the pricing API). The other values map to the API's
 * UP / DOWN / STANDARD bases.
 */
class RoundingBasis implements OptionSourceInterface
{
    public const NONE = 'none';
    public const UP = 'up';
    public const DOWN = 'down';
    public const STANDARD = 'standard';

    /**
     * @inheritDoc
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => self::NONE, 'label' => __('None')],
            ['value' => self::UP, 'label' => __('Up')],
            ['value' => self::DOWN, 'label' => __('Down')],
            ['value' => self::STANDARD, 'label' => __('Standard')],
        ];
    }
}
