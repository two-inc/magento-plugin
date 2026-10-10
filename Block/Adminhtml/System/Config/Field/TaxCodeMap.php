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
use Magento\Store\Model\StoreManagerInterface;
use Magento\Tax\Model\TaxClass\Source\Product as ProductTaxClassSource;
use Two\Gateway\Api\Config\RepositoryInterface as ConfigRepository;
use Two\Gateway\Model\Config\Backend\TaxCodeMap as TaxCodeMapBackend;
use Two\Gateway\Service\Api\TaxCodes;
use Two\Gateway\Service\Merchant\RecordProvider;
use Two\Gateway\Service\Order\ZeroTaxRates;

/**
 * The rows of "Tax codes for 0% lines" (TWO-24877, TWO-26153), each with a
 * dropdown of the Two tax codes for the merchant's country plus "(none)".
 * Every product tax class has a row for a buyer in another EU country with a
 * VAT number, one per 0% tax rate its tax rules use (labelled by the rate's
 * code, country and postcode), and one for an address no rule matches.
 *
 * Each row posts its key and code as a pair (see TaxCodeMapBackend). Saved
 * rows the form does not show (a rate since deleted or no longer 0%), and
 * every saved row when the code list cannot be read, are carried as hidden
 * pairs so a save of the section keeps them.
 */
class TaxCodeMap extends Field
{
    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var RecordProvider
     */
    private $recordProvider;

    /**
     * @var TaxCodes
     */
    private $taxCodes;

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
     * @var int the next row's index in the posted list
     */
    private $index = 0;

    public function __construct(
        Context $context,
        StoreManagerInterface $storeManager,
        RecordProvider $recordProvider,
        TaxCodes $taxCodes,
        ProductTaxClassSource $taxClassSource,
        ConfigRepository $configRepository,
        ZeroTaxRates $zeroTaxRates,
        array $data = []
    ) {
        $this->storeManager = $storeManager;
        $this->recordProvider = $recordProvider;
        $this->taxCodes = $taxCodes;
        $this->taxClassSource = $taxClassSource;
        $this->configRepository = $configRepository;
        $this->zeroTaxRates = $zeroTaxRates;
        parent::__construct($context, $data);
    }

    /**
     * @inheritDoc
     */
    protected function _getElementHtml(AbstractElement $element)
    {
        $saved = TaxCodeMapBackend::normalise($element->getValue());
        $name = (string)$element->getName();
        $storeId = $this->scopeStoreId();
        $record = $storeId !== null ? $this->recordProvider->getRecord($storeId) : null;
        $country = is_string($record['country_code'] ?? null) ? $record['country_code'] : '';
        $codes = $country !== '' ? $this->taxCodes->getSelectable($country, $storeId) : null;

        if ($codes === null) {
            return '<p class="message message-warning">' . $this->escapeHtml(
                __('The tax code list could not be loaded, so the saved choices are kept unchanged. Check the API key, then reload this page.')
            ) . '</p>' . $this->hiddenPairs($name, $saved);
        }

        // Core's None is class 0, which the repository reports as null.
        $shippingClassId = (int)($this->configRepository->getShippingTaxClassId($storeId) ?? 0);
        $zeroRates = $this->zeroTaxRates->byClass();
        $rows = '';
        foreach ($this->taxClassSource->getAllOptions(true) as $class) {
            $classId = (int)$class['value'];
            $label = (string)$class['label'];
            if ($classId === $shippingClassId) {
                $label = (string)__('%1 (shipping)', $label);
            }
            $classRows = [
                TaxCodeMapBackend::exemptKey($classId) => (string)__('Buyer in another EU country with a VAT number'),
            ];
            foreach ($zeroRates[$classId] ?? [] as $rate) {
                $where = in_array($rate['postcode'], ['', '*'], true)
                    ? $rate['country'] : $rate['country'] . ', ' . $rate['postcode'];
                $rateKey = TaxCodeMapBackend::rateKey($classId, $rate['code']);
                $classRows[$rateKey] = sprintf('%s (%s)', $rate['code'], $where);
            }
            $classRows[TaxCodeMapBackend::noRuleKey($classId)] = (string)__('No rule for the address');

            $first = true;
            foreach ($classRows as $key => $rowLabel) {
                $field = $this->escapeHtmlAttr($name) . '[' . $this->index++ . ']';
                $rows .= sprintf(
                    '<tr>%s<td>%s</td><td><input type="hidden" name="%s[key]" value="%s"/>'
                    . '<select class="select admin__control-select" name="%s[code]">%s</select></td></tr>',
                    $first ? sprintf('<td rowspan="%d">%s</td>', count($classRows), $this->escapeHtml($label)) : '',
                    $this->escapeHtml($rowLabel),
                    $field,
                    $this->escapeHtmlAttr((string)$key),
                    $field,
                    $this->options($codes, $saved[$key] ?? '')
                );
                $first = false;
                unset($saved[$key]);
            }
        }

        return sprintf(
            '<table class="admin__control-table"><thead><tr><th>%s</th><th>%s</th><th>%s</th></tr></thead>'
            . '<tbody>%s</tbody></table>',
            $this->escapeHtml(__('Tax class')),
            $this->escapeHtml(__('Line')),
            $this->escapeHtml(__('Tax code')),
            $rows
        ) . $this->hiddenPairs($name, $saved);
    }

    /**
     * @param array<string, string> $rows row key => code
     */
    private function hiddenPairs(string $name, array $rows): string
    {
        $html = '';
        foreach ($rows as $key => $code) {
            $field = $this->escapeHtmlAttr($name) . '[' . $this->index++ . ']';
            $html .= sprintf(
                '<input type="hidden" name="%s[key]" value="%s"/><input type="hidden" name="%s[code]" value="%s"/>',
                $field,
                $this->escapeHtmlAttr((string)$key),
                $field,
                $this->escapeHtmlAttr($code)
            );
        }

        return $html;
    }

    /**
     * "(none)" first, then the list; a saved code the list no longer has stays selectable.
     */
    private function options(array $codes, string $selected): string
    {
        $options = ['' => (string)__('(none)')];
        foreach ($codes as $entry) {
            $name = trim($entry['name']);
            // A rated display name already ends in its rate, "(21%)"; only an unrated one gets it appended.
            $rated = preg_match('/\([^()]*%\)$/', $name) === 1;
            $rate = !$rated && is_numeric($entry['rate']) ? ' (' . (float)$entry['rate'] * 100 . '%)' : '';
            $options[$entry['code']] = trim($entry['code'] . ' ' . $name) . $rate;
        }
        if ($selected !== '' && !isset($options[$selected])) {
            $options[$selected] = $selected;
        }

        $html = '';
        foreach ($options as $value => $label) {
            $html .= sprintf(
                '<option value="%s"%s>%s</option>',
                $this->escapeHtmlAttr((string)$value),
                (string)$value === $selected ? ' selected="selected"' : '',
                $this->escapeHtml($label)
            );
        }

        return $html;
    }

    /**
     * The store the admin scope switcher points at: the store, the website's
     * default store, or the default store view.
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
