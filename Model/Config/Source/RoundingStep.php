<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;
use Two\Gateway\Api\BrandRegistryInterface;

/**
 * Admin "Rounding Step" dropdown options. The selectable steps are
 * brand-driven: each brand declares its set in brand.xml
 * (<surcharge_rounding_steps>), so a brand can offer a narrower set
 * (e.g. 0.50 / 1.00) than the parent default. Values are emitted in a
 * fixed two-decimal canonical form so the stored config value
 * round-trips exactly against the option list (the basis dropdown's
 * None option, not a blank step, is the off switch).
 */
class RoundingStep implements OptionSourceInterface
{
    public function __construct(
        private readonly BrandRegistryInterface $brandRegistry
    ) {
    }

    /**
     * @inheritDoc
     */
    public function toOptionArray(): array
    {
        $options = [];
        foreach ($this->brandRegistry->getSurchargeRoundingSteps() as $step) {
            $value = number_format((float)$step, 2, '.', '');
            $options[] = ['value' => $value, 'label' => $value];
        }
        return $options;
    }
}
