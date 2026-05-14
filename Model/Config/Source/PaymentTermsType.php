<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * Payment Terms Type Source Model
 *
 * Supported payment term types depend on the brand's commercial
 * agreement — see BrandRegistryInterface.
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
        // Filter to the brand's supported payment term types via BrandRegistryInterface.
        return [
            ['value' => self::STANDARD, 'label' => __('Standard')]
        ];
    }
}
