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
use Magento\Framework\Locale\ResolverInterface as LocaleResolverInterface;
use Two\Gateway\Api\Config\RepositoryInterface as ConfigRepository;

class SurchargeTaxRate extends Field
{
    /**
     * @var ConfigRepository
     */
    private $configRepository;

    /** @var LocaleResolverInterface */
    private $localeResolver;

    public function __construct(
        Context $context,
        ConfigRepository $configRepository,
        LocaleResolverInterface $localeResolver,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->configRepository = $configRepository;
        $this->localeResolver = $localeResolver;
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
                    'Leave empty to use your store\'s default tax rate (%1%). Enter 0 for tax-exempt.',
                    number_format($defaultRate, 1, $this->decimalSeparator(), '')
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

        return parent::_getElementHtml($element);
    }

    /**
     * Decimal separator for the current admin locale. Mirrors the
     * SurchargeGrid block helper — kept duplicated rather than
     * factored out because there are only two consumers.
     */
    private function decimalSeparator(): string
    {
        $locale = (string)$this->localeResolver->getLocale() ?: 'en_US';
        $formatter = new \NumberFormatter($locale, \NumberFormatter::DECIMAL);
        $separator = $formatter->getSymbol(\NumberFormatter::DECIMAL_SEPARATOR_SYMBOL);
        return $separator !== false && $separator !== '' ? $separator : '.';
    }
}
