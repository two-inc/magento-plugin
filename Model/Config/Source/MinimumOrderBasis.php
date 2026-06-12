<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * Tax basis for the merchant's minimum order value: whether the basket
 * is compared including (gross) or excluding (net) tax. Matches the
 * basis vocabulary of the API's min_order_basis field.
 */
class MinimumOrderBasis implements OptionSourceInterface
{
    public const GROSS = 'gross';
    public const NET = 'net';

    /**
     * @inheritDoc
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => self::GROSS, 'label' => __('Including tax (gross)')],
            ['value' => self::NET, 'label' => __('Excluding tax (net)')],
        ];
    }
}
