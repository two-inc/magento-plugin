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
use Magento\Framework\Locale\ResolverInterface as LocaleResolver;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Tax\Model\TaxClass\Source\Product as ProductTaxClassSource;
use Two\Gateway\Api\BrandRegistryInterface;
use Two\Gateway\Api\Config\RepositoryInterface as ConfigRepository;
use Two\Gateway\Model\Config\AdminScope;
use Two\Gateway\Model\Config\Backend\TaxCodeMap as TaxCodeMapBackend;
use Two\Gateway\Model\Config\TaxCodeSection;
use Two\Gateway\Model\Config\TaxCodeSectionResolver;
use Two\Gateway\Service\Order\ZeroTaxRates;

/**
 * "Tax codes for 0% lines" (TWO-24877, TWO-26153), rendered as
 * TaxCodeSection decides for the scope being edited: no row at all while it
 * is hidden, else one wide row holding its notices and, while the API requires
 * codes for the merchant's country, a table with one row per product tax
 * class and the cases as columns: a VAT-registered buyer in another EU state,
 * each 0% tax rate the class's rules use, and an address no rule matches.
 *
 * The whole map posts as one hidden field of JSON, which
 * Two_Gateway/js/tax-code-map rewrites from the dropdowns, so a shop with many
 * rates never runs into PHP's max_input_vars. It starts as the saved map, and
 * carries saved rows the table does not show (a rate since deleted or no
 * longer 0%) so a save keeps them. "Clear saved tax codes" is a checkbox of
 * the same name placed after it, so when ticked it is the value posted.
 */
class TaxCodeMap extends Field
{
    /** A code's capitals run wider than the "0" a ch measures. */
    private const CH_PER_CHAR = 1.1;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var TaxCodeSectionResolver
     */
    private $sectionResolver;

    /**
     * @var AdminScope
     */
    private $adminScope;

    /**
     * @var ProductTaxClassSource
     */
    private $taxClassSource;

    /**
     * @var ConfigRepository
     */
    private $configRepository;

    /**
     * @var ZeroTaxRates
     */
    private $zeroTaxRates;

    /**
     * @var BrandRegistryInterface
     */
    private $brandRegistry;

    /**
     * @var LocaleResolver
     */
    private $localeResolver;

    public function __construct(
        Context $context,
        StoreManagerInterface $storeManager,
        TaxCodeSectionResolver $sectionResolver,
        AdminScope $adminScope,
        ProductTaxClassSource $taxClassSource,
        ConfigRepository $configRepository,
        ZeroTaxRates $zeroTaxRates,
        BrandRegistryInterface $brandRegistry,
        LocaleResolver $localeResolver,
        array $data = []
    ) {
        $this->storeManager = $storeManager;
        $this->sectionResolver = $sectionResolver;
        $this->adminScope = $adminScope;
        $this->taxClassSource = $taxClassSource;
        $this->configRepository = $configRepository;
        $this->zeroTaxRates = $zeroTaxRates;
        $this->brandRegistry = $brandRegistry;
        $this->localeResolver = $localeResolver;
        parent::__construct($context, $data);
    }

    /**
     * @inheritDoc
     */
    public function render(AbstractElement $element)
    {
        $request = $this->getRequest();
        [$scopeId, $scope] = $this->adminScope->fromCodes($request->getParam('store'), $request->getParam('website'));
        $state = $this->sectionResolver->resolve($scopeId, $scope, $element->getValue());
        if ($state['view'] === TaxCodeSection::VIEW_HIDDEN) {
            // No row and so nothing posted: the stored mapping is left exactly as it is.
            return '';
        }
        $inherit = $this->_isInheritCheckboxRequired($element);
        $disabled = $inherit && (int)$element->getInherit() === 1;

        return sprintf(
            '<tr id="row_%s"><td colspan="2" class="value two-tax-code-section">%s</td>%s<td></td></tr>',
            $this->escapeHtmlAttr((string)$element->getHtmlId()),
            $this->panel($element, $state, $disabled),
            $inherit ? $this->_renderInheritCheckbox($element) : ''
        );
    }

    /**
     * @param array $state TaxCodeSection::state()
     */
    private function panel(AbstractElement $element, array $state, bool $disabled): string
    {
        $html = sprintf(
            '<h4 class="two-tax-code-section__title">%s%s</h4>',
            $this->escapeHtml((string)$element->getLabel()),
            $this->scopeLabel($element)
        );
        foreach ($this->notices($state) as [$level, $text]) {
            $html .= sprintf(
                '<div class="message message-%1$s %1$s"><div>%2$s</div></div>',
                $level,
                $this->escapeHtml($text)
            );
        }
        if ($state['view'] === TaxCodeSection::VIEW_ROWS) {
            $html .= sprintf('<p class="note">%s</p>', $this->escapeHtml((string)__(
                '%1 requires a tax code on every 0% line for your country (%2): choose one for each case below. A case left on (none) sends no code, and %1 refuses the line. Shipping takes the rows of the Tax Class for Shipping, marked (shipping), and a line with no tax class takes the code the order\'s other 0% lines share. Northern Ireland counts as EU for goods only: in a services class\'s EU column, don\'t choose an intra-community services code.',
                $this->brandRegistry->getProductName(),
                $this->countryName($state['country'])
            )));
            $html .= $this->table($element, $state['codes'], $disabled);
        }
        if ($state['clear']) {
            $html .= sprintf(
                '<p><label><input type="checkbox" class="admin__control-checkbox" name="%s" value="%s"%s/> %s</label></p>',
                $this->escapeHtmlAttr((string)$element->getName()),
                TaxCodeMapBackend::CLEAR,
                $disabled ? ' disabled="disabled"' : '',
                $this->escapeHtml(__('Clear saved tax codes'))
            );
        }

        return $html;
    }

    /**
     * The scope the setting is stored at ("[STORE VIEW]"), which core shows
     * beside every field's label, and hides on a single-store install.
     */
    private function scopeLabel(AbstractElement $element): string
    {
        $label = (string)$element->getScopeLabel();
        if (!$element->getScope() || $label === '' || $this->storeManager->isSingleStoreMode()) {
            return '';
        }

        return sprintf(
            ' <span class="two-tax-code-section__scope" data-config-scope="%1$s">%2$s</span>',
            $this->escapeHtmlAttr($label),
            $this->escapeHtml($label)
        );
    }

    /**
     * Why the rows are not shown, then the section's notices, each as [level, text].
     *
     * @param array $state TaxCodeSection::state()
     * @return array<int, array{0: string, 1: string}>
     */
    private function notices(array $state): array
    {
        $notices = [];
        $error = TaxCodeSection::error($state, $this->brandRegistry->getProductName(), $this->countryName($state['country']));
        if ($error !== null) {
            $notices[] = ['warning', $error];
        }
        $brand = $this->brandRegistry->getProductName();
        $country = $this->countryName($state['country']);
        foreach ($state['notices'] as $notice) {
            switch ($notice) {
                case TaxCodeSection::NOTICE_UNREADABLE:
                    $notices[] = ['error', (string)__(
                        'Saved tax codes can\'t be read, so %1 is hidden at checkout; clear them to restore it.',
                        $brand
                    )];
                    break;
                case TaxCodeSection::NOTICE_SCOPE:
                    $notices[] = ['notice', (string)__(
                        'Some websites or store views below this scope use another API key or environment, so their merchant country and tax codes may differ: check this setting in each of their scopes.'
                    )];
                    break;
                case TaxCodeSection::NOTICE_SAVED_VALID:
                    $notices[] = ['notice', (string)__(
                        'Saved tax codes still apply at checkout, though %1 does not require them for your country (%2).',
                        $brand,
                        $country
                    )];
                    break;
                case TaxCodeSection::NOTICE_SAVED_INVALID:
                    $notices[] = ['warning', (string)__(
                        'Some saved tax codes are not valid on 0% lines for your country (%1), so %2 refuses orders that use them.',
                        $country,
                        $brand
                    )];
                    break;
            }
        }

        return $notices;
    }

    /**
     * The mapping as a table, one row per product tax class. Every select
     * offers (none) and the listed codes at rate 0, plus a saved code that is
     * not one of them, marked, so nothing saved disappears unseen.
     *
     * @param array<int, array{code: string, name: string, rate: string}> $codes
     */
    private function table(AbstractElement $element, array $codes, bool $disabled): string
    {
        $saved = TaxCodeMapBackend::read($element->getValue()) ?? [];
        $htmlId = (string)$element->getHtmlId();
        $options = [];
        foreach (TaxCodeSection::zeroRated($codes) as $entry) {
            $name = trim($entry['name']);
            $options[$entry['code']] = $entry['code'] . ($name !== '' ? ' - ' . $name : '');
        }
        $listed = array_column($codes, 'code');
        $classes = $this->classes();
        $longest = 0;
        foreach (array_merge(array_keys($options), array_values($saved)) as $code) {
            $longest = max($longest, strlen((string)$code));
        }
        // Wide enough to show the longest code when closed, as goods and services codes can differ only at the
        // end; the name after the code may clip. The rest is the select's padding and arrow.
        $width = sprintf('min-width:calc(%dch + 3em);max-width:100%%;', (int)ceil($longest * self::CH_PER_CHAR));

        $carried = $saved;
        $body = '';
        foreach ($classes as $classId => $class) {
            $exempt = TaxCodeMapBackend::exemptKey($classId);
            $none = TaxCodeMapBackend::noRuleKey($classId);
            $rates = '';
            foreach ($class['rates'] as $key => $label) {
                $rates .= sprintf(
                    '<div class="two-tax-code-section__rate"><div class="note">%s</div>%s</div>',
                    $this->escapeHtml($label),
                    $this->select($key, $class['name'] . ': ' . self::caption('rates') . ': ' . $label, $saved, $options, $listed, $width, $disabled)
                );
                unset($carried[$key]);
            }
            if ($rates === '') {
                $rates = sprintf('<span class="note">%s</span>', $this->escapeHtml(__('No 0% tax rate in this class')));
            }
            unset($carried[$exempt], $carried[$none]);
            $body .= sprintf(
                '<tr><th scope="row">%s</th><td>%s</td><td>%s</td><td>%s</td></tr>',
                $this->escapeHtml($class['name']),
                $this->select($exempt, $class['name'] . ': ' . self::caption('exempt'), $saved, $options, $listed, $width, $disabled),
                $rates,
                $this->select($none, $class['name'] . ': ' . self::caption('none'), $saved, $options, $listed, $width, $disabled)
            );
        }

        return sprintf(
            '<input type="hidden" id="%s" name="%s" value="%s" data-carried="%s"%s/>',
            $this->escapeHtmlAttr($htmlId),
            $this->escapeHtmlAttr((string)$element->getName()),
            $this->escapeHtmlAttr((string)json_encode((object)$saved)),
            $this->escapeHtmlAttr((string)json_encode((object)$carried)),
            $disabled ? ' disabled="disabled"' : ''
        ) . sprintf(
            '<div id="%s_rows" style="overflow-x:auto;"><table class="admin__control-table" style="width:100%%;">'
            . '<thead><tr><th scope="col">%s</th><th scope="col">%s</th><th scope="col">%s</th><th scope="col">%s</th>'
            . '</tr></thead><tbody>%s</tbody></table></div>',
            $this->escapeHtmlAttr($htmlId),
            $this->escapeHtml(__('Tax class')),
            $this->escapeHtml(self::caption('exempt')),
            $this->escapeHtml(self::caption('rates')),
            $this->escapeHtml(self::caption('none')),
            $body
        ) . sprintf(
            '<script type="text/x-magento-init">%s</script>',
            (string)json_encode(
                ['#' . $htmlId . '_rows' => ['Two_Gateway/js/tax-code-map' => ['input' => $htmlId]]],
                JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES
            )
        );
    }

    /**
     * Every product tax class (None included) with its 0% rates, labelled by
     * the rate's code, country and postcode.
     *
     * @return array<int, array{name: string, rates: array<string, string>}>
     */
    private function classes(): array
    {
        // Core's None is class 0, which the repository reports as null.
        $shippingClassId = (int)($this->configRepository->getShippingTaxClassId($this->scopeStoreId()) ?? 0);
        $zeroRates = $this->zeroTaxRates->byClass();
        $classes = [];
        foreach ($this->taxClassSource->getAllOptions(true) as $class) {
            $classId = (int)$class['value'];
            $name = (string)$class['label'];
            if ($classId === $shippingClassId) {
                $name = (string)__('%1 (shipping)', $name);
            }
            $rates = [];
            foreach ($zeroRates[$classId] ?? [] as $rate) {
                $where = in_array($rate['postcode'], ['', '*'], true)
                    ? $rate['country'] : $rate['country'] . ', ' . $rate['postcode'];
                $rates[TaxCodeMapBackend::rateKey($classId, $rate['code'])] = sprintf('%s (%s)', $rate['code'], $where);
            }
            $classes[$classId] = ['name' => $name, 'rates' => $rates];
        }

        return $classes;
    }

    /**
     * @param array<string, string> $saved
     * @param array<string, string> $options code => label, the codes offered for 0% lines
     * @param string[] $listed every code the list holds, at any rate
     */
    private function select(
        string $key,
        string $name,
        array $saved,
        array $options,
        array $listed,
        string $width,
        bool $disabled
    ): string {
        $current = $saved[$key] ?? '';
        $options = ['' => (string)__('(none)')] + $options;
        $marker = '';
        if ($current !== '' && !isset($options[$current])) {
            $marker = in_array($current, $listed, true)
                ? (string)__('not valid on 0% lines') : (string)__('no longer listed');
            $options[$current] = $current . ' (' . $marker . ')';
        }
        $markerId = 'two-tax-code-marker-' . md5($key);
        $html = sprintf(
            '<select class="select admin__control-select" data-key="%s" style="%s" aria-label="%s"%s%s>',
            $this->escapeHtmlAttr($key),
            $width,
            $this->escapeHtmlAttr($name),
            $marker !== '' ? ' aria-describedby="' . $markerId . '"' : '',
            $disabled ? ' disabled="disabled"' : ''
        );
        foreach ($options as $value => $label) {
            $html .= sprintf(
                '<option value="%s"%s>%s</option>',
                $this->escapeHtmlAttr((string)$value),
                (string)$value === $current ? ' selected="selected"' : '',
                $this->escapeHtml($label)
            );
        }
        $html .= '</select>';
        if ($marker !== '') {
            $html .= sprintf(
                '<div id="%s" class="note two-tax-code-section__marker">%s</div>',
                $markerId,
                $this->escapeHtml($current . ': ' . $marker)
            );
        }

        return $html;
    }

    /**
     * A column of the table: `exempt`, `rates` or `none`.
     */
    private static function caption(string $column): string
    {
        switch ($column) {
            case 'exempt':
                return (string)__('VAT-registered buyer, other EU state');
            case 'rates':
                return (string)__('Your 0% tax rates');
            default:
                return (string)__('Buyer\'s address does not map to a tax rule (no tax charged, e.g. exports)');
        }
    }

    /**
     * The country's name in the admin's language, falling back to its ISO
     * code, including where PHP has no intl extension to name it.
     */
    private function countryName(string $iso): string
    {
        if ($iso === '' || !class_exists(\Locale::class, false) || !method_exists(\Locale::class, 'getDisplayRegion')) {
            return $iso;
        }
        $name = \Locale::getDisplayRegion('-' . $iso, (string)$this->localeResolver->getLocale());

        return is_string($name) && $name !== '' && $name !== $iso ? $name : $iso;
    }

    /**
     * The store the admin scope switcher points at, for the Tax Class for
     * Shipping mark: the store, the website's default store, or the default
     * store view.
     */
    private function scopeStoreId(): ?int
    {
        try {
            if ($storeCode = $this->getRequest()->getParam('store')) {
                return (int)$this->storeManager->getStore($storeCode)->getId();
            }
            if ($websiteCode = $this->getRequest()->getParam('website')) {
                return (int)$this->storeManager->getWebsite($websiteCode)->getDefaultGroup()->getDefaultStoreId();
            }
            $default = $this->storeManager->getDefaultStoreView();
            return $default ? (int)$default->getId() : null;
        } catch (\Exception $e) {
            return null;
        }
    }
}
