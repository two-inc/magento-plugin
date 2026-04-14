<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * Options for surcharge calculation basis.
 *
 * Values are 0/1 for backwards compatibility with the original Yes/No toggle.
 */
class SurchargeCalculationBasis implements OptionSourceInterface
{
    public const FULL_FEE = '0';
    public const DIFFERENTIAL = '1';

    /**
     * @inheritDoc
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => self::FULL_FEE, 'label' => __('Total fee for selected term')],
            ['value' => self::DIFFERENTIAL, 'label' => __('Fee difference vs default payment term')],
        ];
    }
}
