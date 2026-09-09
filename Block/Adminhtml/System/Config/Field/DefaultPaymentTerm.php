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
use Two\Gateway\Model\Config\AdminScope;
use Two\Gateway\Model\Config\Repository;
use Two\Gateway\Service\Merchant\SettingsProvider;

/**
 * Renders the "Default payment term" select.
 *
 * With no usable stored value the field is pre-selected the way the
 * checkout resolves its default — the merchant's API default term
 * (due_in_days), then 30, then the lowest offered term — so the admin
 * shows the term the buyer will see (TWO-24859, ABN-548). The value is
 * injected for display only, so a later admin edit is stored normally
 * and wins.
 *
 * etc/config.xml deliberately carries no static default for this field
 * so an empty stored value genuinely means "admin never chose".
 */
class DefaultPaymentTerm extends Field
{
    /** @var SettingsProvider */
    private $settingsProvider;

    /** @var AdminScope */
    private $adminScope;

    public function __construct(
        Context $context,
        SettingsProvider $settingsProvider,
        AdminScope $adminScope,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->settingsProvider = $settingsProvider;
        $this->adminScope = $adminScope;
    }

    /**
     * @inheritDoc
     */
    protected function _getElementHtml(AbstractElement $element): string
    {
        [$scopeId, $scope] = $this->resolveScope();
        $terms = array_map('intval', $this->settingsProvider->getAvailableTerms($scopeId, $scope));
        // A stored term the merchant no longer offers has no option to select,
        // so the select would silently read as the lowest one.
        if (!in_array((int)$element->getValue(), $terms, true)) {
            $apiDefault = $this->settingsProvider->getDefaultTerm($scopeId, $scope);
            if ($apiDefault !== null && in_array($apiDefault, $terms, true)) {
                $element->setValue((string)$apiDefault);
            } elseif (in_array(Repository::PREFERRED_DEFAULT_TERM, $terms, true)) {
                $element->setValue((string)Repository::PREFERRED_DEFAULT_TERM);
            } elseif (count($terms) > 0) {
                sort($terms);
                $element->setValue((string)$terms[0]);
            }
        }
        return parent::_getElementHtml($element);
    }

    /**
     * Scope being edited, from the form's own URL params rather than the form object.
     *
     * @return array{int|null, string}
     */
    private function resolveScope(): array
    {
        return $this->adminScope->fromCodes(
            $this->getRequest()->getParam('store'),
            $this->getRequest()->getParam('website')
        );
    }
}
