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

/**
 * One row per product tax class, each with a dropdown of the Two tax codes
 * for the merchant's country plus "(none)" (TWO-24877).
 *
 * When the list cannot be read, the saved choices are carried as hidden
 * fields so a save of the section keeps them.
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

    public function __construct(
        Context $context,
        StoreManagerInterface $storeManager,
        RecordProvider $recordProvider,
        TaxCodes $taxCodes,
        ProductTaxClassSource $taxClassSource,
        ConfigRepository $configRepository,
        array $data = []
    ) {
        $this->storeManager = $storeManager;
        $this->recordProvider = $recordProvider;
        $this->taxCodes = $taxCodes;
        $this->taxClassSource = $taxClassSource;
        $this->configRepository = $configRepository;
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
            $html = '<p class="message message-warning">' . $this->escapeHtml(
                __('The tax code list could not be loaded, so the saved choices are kept unchanged. Check the API key, then reload this page.')
            ) . '</p>';
            foreach ($saved as $classId => $code) {
                $html .= sprintf(
                    '<input type="hidden" name="%s[%s]" value="%s"/>',
                    $this->escapeHtmlAttr($name),
                    $this->escapeHtmlAttr($classId),
                    $this->escapeHtmlAttr($code)
                );
            }
            return $html;
        }

        // Core's None is class 0, which the repository reports as null.
        $shippingClassId = (string)($this->configRepository->getShippingTaxClassId($storeId) ?? 0);
        $rows = '';
        foreach ($this->taxClassSource->getAllOptions(true) as $class) {
            $classId = (string)$class['value'];
            $label = (string)$class['label'];
            if ($classId === $shippingClassId) {
                $label = (string)__('%1 (shipping)', $label);
            }
            $rows .= sprintf(
                '<tr><td>%s</td><td><select class="select admin__control-select" name="%s[%s]">%s</select></td></tr>',
                $this->escapeHtml($label),
                $this->escapeHtmlAttr($name),
                $this->escapeHtmlAttr($classId),
                $this->options($codes, $saved[$classId] ?? '')
            );
        }

        return sprintf(
            '<table class="admin__control-table"><thead><tr><th>%s</th><th>%s</th></tr></thead><tbody>%s</tbody></table>',
            $this->escapeHtml(__('Tax class')),
            $this->escapeHtml(__('Tax code')),
            $rows
        );
    }

    /**
     * "(none)" first, then the list; a saved code the list no longer has stays selectable.
     */
    private function options(array $codes, string $selected): string
    {
        $options = ['' => (string)__('(none)')];
        foreach ($codes as $entry) {
            $rate = is_numeric($entry['rate']) ? ' (' . (float)$entry['rate'] * 100 . '%)' : '';
            $options[$entry['code']] = trim($entry['code'] . ' ' . $entry['name']) . $rate;
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
