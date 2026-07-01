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
use Two\Gateway\Service\Locale\AdminDecimalFormatter;

class SurchargeTaxRate extends Field
{
    /**
     * @var ConfigRepository
     */
    private $configRepository;

    /** @var AdminDecimalFormatter */
    private $decimalFormatter;

    public function __construct(
        Context $context,
        ConfigRepository $configRepository,
        AdminDecimalFormatter $decimalFormatter,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->configRepository = $configRepository;
        $this->decimalFormatter = $decimalFormatter;
    }

    /**
     * @inheritDoc
     */
    protected function _getElementHtml(AbstractElement $element): string
    {
        $defaultRate = $this->configRepository->getDefaultTaxRate();
        $separator = $this->decimalFormatter->getSeparator();

        if ($defaultRate > 0) {
            $element->setComment(
                (string)__(
                    'Leave empty to use your store\'s default tax rate (%1%). Enter 0 for tax-exempt.',
                    number_format($defaultRate, 1, $separator, '')
                )
            );
        } else {
            $taxRatesUrl = $this->getUrl('tax/rate/index');
            $taxRulesUrl = $this->getUrl('tax/rule/index');
            $taxConfigUrl = $this->getUrl('adminhtml/system_config/edit/section/tax');
            $generalConfigUrl = $this->getUrl('adminhtml/system_config/edit/section/general');

            $element->setComment(
                '<span class="surcharge-tax-warning">'
                . (string)__('Warning: No tax rules are configured for your store. '
                    . 'Enter a rate manually, or enter 0 for tax-exempt.')
                . '<br/><br/>'
                . (string)__('To set up automatic tax resolution:')
                . ' <a href="' . $taxRatesUrl . '">1. Tax Rates</a>'
                . ' → <a href="' . $taxRulesUrl . '">2. Tax Rules</a>'
                . ' → <a href="' . $generalConfigUrl . '#general_country-link">3. Store Country</a>'
                . ' → <a href="' . $taxConfigUrl . '#tax_defaults-link">4. Default Tax Country</a>'
                . '</span>'
            );
        }

        // Locale-format the stored value (canonical "21.5") into
        // the admin's separator (e.g. "21,5" under nl_NL) before
        // it renders into the input's value attribute. The
        // validate-zero-or-greater validator routes through
        // $.mage.parseNumber, which is locale-aware, so the
        // comma round-trips. Save-side normalisation happens
        // in the LocaleDecimal backend model.
        $value = $element->getValue();
        if (is_string($value) && $value !== '') {
            $element->setValue(str_replace('.', $separator, $value));
        }

        return parent::_getElementHtml($element);
    }
}
