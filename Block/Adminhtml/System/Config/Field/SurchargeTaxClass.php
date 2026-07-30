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
use Two\Gateway\Model\Config\NeverTaxedTreatment;

/**
 * Renderer for the Surcharge Tax Treatment field: fails LOUD when the
 * value this scope has stored is a never-taxed treatment (TWO-25279).
 *
 * Never-taxed treatments — core "None" (Product Tax Class id 0) and the
 * "Payment Terms Surcharge - No Tax" class the plugin used to provision —
 * are no longer offered in the dropdown, and are refused at save time by
 * Two\Gateway\Model\Config\Backend\SurchargeTaxClass. There is
 * deliberately no grandfathering, which leaves one case to handle
 * honestly: a scope configured before this change, or written outside the
 * admin form, that still holds such a value.
 *
 * That scope is NOT silently corrected and NOT silently left on a zero.
 * The merchant sees an error on the field itself telling them to select a
 * real tax rule. Doing nothing would be the worst option: the select
 * cannot render a value absent from its options, so it falls back to the
 * placeholder and the field simply looks unset, giving no hint that the
 * surcharge is currently being charged untaxed.
 *
 * Reads $element->getValue(), NOT config: Magento has already resolved
 * the value for the exact scope being edited (including inheritance), so
 * there is no scope or brand resolution to get wrong here.
 *
 * The never-taxed decision itself is delegated to
 * Two\Gateway\Model\Config\NeverTaxedTreatment, shared with the backend
 * model, so the warning covers exactly the set the save refuses.
 */
class SurchargeTaxClass extends Field
{
    /**
     * @var NeverTaxedTreatment
     */
    private $neverTaxedTreatment;

    public function __construct(
        Context $context,
        NeverTaxedTreatment $neverTaxedTreatment,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->neverTaxedTreatment = $neverTaxedTreatment;
    }

    /**
     * @inheritDoc
     */
    protected function _getElementHtml(AbstractElement $element): string
    {
        if ($this->neverTaxedTreatment->isNeverTaxed((string)$element->getValue())) {
            $taxRulesUrl = $this->getUrl('tax/rule/index');
            $element->setComment(
                '<span class="surcharge-tax-warning">'
                . (string)__(
                    'This store is set to a surcharge tax treatment that leaves the surcharge '
                    . 'untaxed in every jurisdiction. That treatment is no longer available and '
                    . 'this configuration can no longer be saved while it is selected.'
                )
                . ' '
                . (string)__(
                    'Create a Tax Rule with a 0% rate and select its Product Tax Class here.'
                )
                . ' <a href="' . $taxRulesUrl . '">' . (string)__('Manage Tax Rules') . '</a>'
                . '</span>'
            );
        }

        return parent::_getElementHtml($element);
    }
}
