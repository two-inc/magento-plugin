<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Service\Locale;

use Magento\Framework\Locale\ResolverInterface as LocaleResolverInterface;

/**
 * Resolves the decimal separator for the active admin locale via ICU.
 * Shared by the surcharge-grid block, the surcharge-tax-rate block,
 * and the payment-terms-checkboxes block — all of which need to
 * render canonical period-decimal storage with the admin's separator
 * (e.g. "21.5" → "21,5" under nl_NL).
 *
 * Storage stays period-canonical; this is display-formatting only.
 */
class AdminDecimalFormatter
{
    /** @var LocaleResolverInterface */
    private $localeResolver;

    public function __construct(LocaleResolverInterface $localeResolver)
    {
        $this->localeResolver = $localeResolver;
    }

    /**
     * Decimal separator for the current admin locale. Same ICU
     * data that $.mage.parseNumber reads on the JS side, so what
     * we render is what the locale-aware validators accept.
     */
    public function getSeparator(): string
    {
        $locale = (string)$this->localeResolver->getLocale() ?: 'en_US';
        $formatter = new \NumberFormatter($locale, \NumberFormatter::DECIMAL);
        $separator = $formatter->getSymbol(\NumberFormatter::DECIMAL_SEPARATOR_SYMBOL);
        return $separator !== false && $separator !== '' ? $separator : '.';
    }
}
