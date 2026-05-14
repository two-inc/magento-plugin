<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;
use Two\Gateway\Api\BrandRegistryInterface;
use Two\Gateway\Api\Config\RepositoryInterface;

/**
 * Available Payment Terms Source Model (multiselect)
 */
class AvailablePaymentTerms implements OptionSourceInterface
{
    /** @var BrandRegistryInterface */
    private $brandRegistry;

    public function __construct(BrandRegistryInterface $brandRegistry)
    {
        $this->brandRegistry = $brandRegistry;
    }

    /**
     * @inheritDoc
     */
    public function toOptionArray(): array
    {
        $options = [];
        foreach ($this->brandRegistry->getAvailablePaymentTerms() as $days) {
            $options[] = ['value' => $days, 'label' => __('%1 days', $days)];
        }
        return $options;
    }
}
