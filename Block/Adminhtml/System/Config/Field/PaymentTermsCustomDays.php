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

    public function __construct(
        Context $context,
        OfferedTermsGuard $offeredTerms,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->offeredTerms = $offeredTerms;
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
            // data-two-term carries this normalisation to the admin scripts, which must not
            // re-derive a term from the raw value (ABN-522).
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
        ) . $this->foldsInMarker($element, $days);
    }

    /** Marks the row the save will fold into an offered term's checkbox; it stays posted, hidden. */
    private function foldsInMarker(AbstractElement $element, ?int $days): string
    {
        if ($days === null) {
            return '';
        }
        $offered = $this->offeredTerms->offered($this->resolveStoreId($element));

        return $offered !== [] && in_array($days, $offered, true)
            ? '<span class="two-legacy-term-folds-in" hidden="hidden"></span>'
            : '';
    }

    /**
     * Store id for the active config scope, or null for website/default — the offered-terms
     * lookup resolves the per-store API key from it.
     */
    private function resolveStoreId(AbstractElement $element): ?int
    {
        $form = $element->getForm();
        if (!$form) {
            return null;
        }

        return (string)$form->getScope() === 'stores' && (int)$form->getScopeId() > 0
            ? (int)$form->getScopeId()
            : null;
    }
}
