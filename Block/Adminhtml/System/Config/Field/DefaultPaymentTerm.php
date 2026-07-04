<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Block\Adminhtml\System\Config\Field;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Two\Gateway\Service\Merchant\SettingsProvider;

/**
 * Renders the "Default Payment Term" select.
 *
 * When the admin has not saved an explicit value, the field is
 * pre-selected to the merchant's API default term (due_in_days), so a
 * fresh install shows — and the checkout uses — the same term. Because
 * the value is only injected for display (never persisted), a later
 * admin edit is stored normally and wins: the API provides the default,
 * it does not override an explicit choice (TWO-24859).
 *
 * etc/config.xml deliberately carries no static default for this field
 * so an empty stored value genuinely means "admin never chose".
 */
class DefaultPaymentTerm extends Field
{
    /** @var SettingsProvider */
    private $settingsProvider;

    public function __construct(
        Context $context,
        SettingsProvider $settingsProvider,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->settingsProvider = $settingsProvider;
    }

    /**
     * @inheritDoc
     */
    protected function _getElementHtml(AbstractElement $element): string
    {
        if ((string)$element->getValue() === '') {
            $storeId = $this->resolveStoreId($element);
            $terms = array_map('intval', $this->settingsProvider->getAvailableTerms($storeId));
            $apiDefault = $this->settingsProvider->getDefaultTerm($storeId);
            if ($apiDefault !== null && in_array($apiDefault, $terms, true)) {
                $element->setValue((string)$apiDefault);
            } elseif (count($terms) > 0) {
                // No usable API default: fall back to the lowest offered term
                // so the select never renders with an out-of-set selection.
                sort($terms);
                $element->setValue((string)$terms[0]);
            }
        }
        return parent::_getElementHtml($element);
    }

    /**
     * Store id for the active config scope, or null for website/default
     * scope — used to resolve the per-store API key when reading merchant
     * settings.
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
