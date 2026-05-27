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
     *
     * Both options are always returned. Brands that don't offer
     * End-of-Month suppress the whole field via their brand.xml
     * `<suppressed_fields>` (e.g. ABN), so per-brand filtering on
     * this list is unnecessary.
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => self::STANDARD, 'label' => __('Standard')],
            ['value' => self::END_OF_MONTH, 'label' => __('End of Month')]
        ];
    }
}
