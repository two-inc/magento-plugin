<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Block\Adminhtml\System\Config\Field;

use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Two\Gateway\Api\Config\RepositoryInterface as ConfigRepository;

/**
 * Surcharge Tax Rate field with dynamic comment.
 *
 * Shows the store's default tax rate when available, or a red warning
 * when no tax rules are configured.
 */
class SurchargeTaxRate extends Field
{
    /**
     * @var ConfigRepository
     */
    private $configRepository;

    public function __construct(
        Context $context,
        ConfigRepository $configRepository,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->configRepository = $configRepository;
    }

    /**
     * @inheritDoc
     */
    protected function _getElementHtml(AbstractElement $element): string
    {
        $defaultRate = $this->configRepository->getDefaultTaxRate();

        if ($defaultRate > 0) {
            $element->setComment(
                (string)__(
                    'Leave empty to use your store\'s default tax rate (%1%%). Enter 0 for tax-exempt.',
                    number_format($defaultRate, 1)
                )
            );
        } else {
            $element->setComment(
                '<span style="color: #e22626; font-weight: bold;">'
                . (string)__('Warning: No tax rules are configured for your store. '
                    . 'Configure tax rules in Stores → Tax Rules to ensure correct surcharge tax calculation. '
                    . 'Enter a rate manually, or enter 0 for tax-exempt.')
                . '</span>'
            );
        }

        return parent::_getElementHtml($element);
    }
}
