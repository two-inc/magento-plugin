<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Plugin\Magento\Sales\Ui\Component\Listing\Column;

use Magento\Sales\Ui\Component\Listing\Column\Price;
use Two\Gateway\Api\BrandRegistryInterface;

/**
 * Fills the `%1` in the surcharge column's label with the active brand's
 * product name. A ui_component label is static XML with no brand token, so
 * the substitution has to happen on the component.
 */
class BrandSurchargeColumnLabel
{
    private const COLUMN_NAME = 'two_surcharge_amount';

    public function __construct(
        private readonly BrandRegistryInterface $brandRegistry
    ) {
    }

    public function beforePrepare(Price $subject): void
    {
        if ($subject->getName() !== self::COLUMN_NAME) {
            return;
        }

        $config = $subject->getData('config');
        if (!is_array($config) || !isset($config['label'])) {
            return;
        }

        try {
            $product = $this->brandRegistry->getProductName();
        } catch (\Throwable $e) {
            return;
        }

        $config['label'] = (string)__((string)$config['label'], $product);
        $subject->setData('config', $config);
    }
}
