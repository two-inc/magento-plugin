<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace ABN\Gateway\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * Payment Terms Type Source Model
 * ABN only supports standard payment terms
 */
class PaymentTermsType implements OptionSourceInterface
{
    public const STANDARD = 'standard';
    public const END_OF_MONTH = 'end_of_month';

    /**
     * @inheritDoc
     */
    public function toOptionArray(): array
    {
        // ABN only supports standard payment terms
        return [
            ['value' => self::STANDARD, 'label' => __('Standard')]
        ];
    }
}
