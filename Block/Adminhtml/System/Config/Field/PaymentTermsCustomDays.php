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
use Two\Gateway\Model\Config\Backend\PaymentTerms\OfferedTermsGuard;
use Two\Gateway\Model\Config\StoredTerm;

/**
 * Renders the deprecated custom term as keep-or-remove, so the only edit the merchant is
 * offered is the only one the save accepts (ABN-522).
 */
class PaymentTermsCustomDays extends Field
{
    /** @var OfferedTermsGuard */
    private $offeredTerms;

    /** @var StoreManagerInterface */
    private $storeManager;

    public function __construct(
        Context $context,
        OfferedTermsGuard $offeredTerms,
        StoreManagerInterface $storeManager,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->offeredTerms = $offeredTerms;
        $this->storeManager = $storeManager;
    }

    /**
     * @inheritDoc
     */
    protected function _getElementHtml(AbstractElement $element): string
    {
        $stored = trim((string)$element->getValue());
        $days = StoredTerm::days($stored);
        $keepLabel = $days === null ? $stored : (string)__('%1 days', $days);
        $remove = ['', (string)__('Remove'), 0];
        $options = $stored === '' ? [$remove] : [[$stored, $keepLabel, $days ?? 0], $remove];

        $optionsHtml = '';
        foreach ($options as [$value, $label, $term]) {
            $optionsHtml .= sprintf(
                '<option value="%s" data-two-term="%d"%s>%s</option>',
                $this->escapeHtmlAttr($value),
                $term,
                $value === $stored ? ' selected="selected"' : '',
                $this->escapeHtml($label)
            );
        }

        return sprintf(
            '<select id="%s" name="%s" class="select"%s>%s</select>',
            $this->escapeHtmlAttr((string)$element->getHtmlId()),
            $this->escapeHtmlAttr((string)$element->getName()),
            $element->getDisabled() ? ' disabled="disabled"' : '',
            $optionsHtml
        ) . $this->foldsInMarker($days);
    }

    /** Marks the row the save will fold into an offered term's checkbox; it stays posted, hidden. */
    private function foldsInMarker(?int $days): string
    {
        if ($days === null) {
            return '';
        }
        $offered = $this->offeredTerms->offered($this->resolveStoreId());

        return $offered !== [] && in_array($days, $offered, true)
            ? '<span class="two-legacy-term-folds-in" hidden="hidden"></span>'
            : '';
    }

    /**
     * Store id for the scope being edited, or null for website/default — the offered-terms lookup
     * resolves the per-store API key from it.
     *
     * @see SurchargeGrid::resolveScope() for why the request param and not the form object.
     */
    private function resolveStoreId(): ?int
    {
        $store = $this->getRequest()->getParam('store');
        if ($store === null || $store === '') {
            return null;
        }

        try {
            return (int)$this->storeManager->getStore($store)->getId() ?: null;
        } catch (\Exception $e) {
            return null;
        }
    }
}
