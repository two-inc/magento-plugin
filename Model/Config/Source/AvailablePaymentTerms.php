<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;
use Two\Gateway\Api\Config\RepositoryInterface;

/**
 * Available Payment Terms Source Model (multiselect)
 */
class AvailablePaymentTerms implements OptionSourceInterface
{
    /**
     * @inheritDoc
     */
    public function toOptionArray(): array
    {
        $options = [];
        foreach (RepositoryInterface::AVAILABLE_PAYMENT_TERMS as $days) {
            $options[] = ['value' => $days, 'label' => __('%1 days', $days)];
        }
        return $options;
    }
}
