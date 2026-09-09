<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Block\Adminhtml\System\Config\Field;

use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Config\Model\Config\Reader\Source\Deployed\SettingChecker;
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
    /** Sibling holding the term checkboxes, whose tick is the other half of the fold-in. */
    private const SIBLING = 'payment_terms';

    /** @var OfferedTermsGuard */
    private $offeredTerms;

    /** @var StoreManagerInterface */
    private $storeManager;

    /** @var SettingChecker */
    private $settingChecker;

    /** @var string|null */
    private $scope;

    /** @var string|null */
    private $scopeCode;

    /** @var int|null */
    private $storeId;

    public function __construct(
        Context $context,
        OfferedTermsGuard $offeredTerms,
        StoreManagerInterface $storeManager,
        SettingChecker $settingChecker,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->offeredTerms = $offeredTerms;
        $this->storeManager = $storeManager;
        $this->settingChecker = $settingChecker;
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
        ) . $this->foldsInMarker($element, $days);
    }

    /** Marks the row the save may fold into an offered term's checkbox; it stays posted, hidden. */
    private function foldsInMarker(AbstractElement $element, ?int $days): string
    {
        if ($days === null || !$this->siblingCanTakeTheTerm($element)) {
            return '';
        }
        $offered = $this->offeredTerms->offered($this->resolveStoreId());

        return $offered !== [] && in_array($days, $offered, true)
            ? '<span class="two-legacy-term-folds-in" hidden="hidden"></span>'
            : '';
    }

    /**
     * Env.php-locking the sibling stops it reaching its backend model; its inherit state is only
     * known in the browser, so the JS composes that half with this marker.
     */
    private function siblingCanTakeTheTerm(AbstractElement $element): bool
    {
        $structurePath = $element->getData('field_config')['path'] ?? null;
        if (!is_string($structurePath) || $structurePath === '') {
            return false;
        }
        $this->resolveScope();

        return !$this->settingChecker->isReadOnly(
            $structurePath . '/' . self::SIBLING,
            (string)$this->scope,
            $this->scopeCode
        );
    }

    /** Store id for the scope being edited, or null for website/default — resolves the API key. */
    private function resolveStoreId(): ?int
    {
        $this->resolveScope();

        return $this->storeId;
    }

    /**
     * Scope being edited, named as the config save pipeline names it.
     *
     * @see SurchargeGrid::resolveScope() for why the request params and not the form object.
     */
    private function resolveScope(): void
    {
        if ($this->scope !== null) {
            return;
        }

        $store = (string)$this->getRequest()->getParam('store');
        $website = (string)$this->getRequest()->getParam('website');

        if ($store !== '') {
            try {
                $resolved = $this->storeManager->getStore($store);
                $this->scopeCode = (string)$resolved->getCode();
                $this->storeId = (int)$resolved->getId() ?: null;
                $this->scope = 'stores';

                return;
            } catch (\Exception $e) {
                $this->scopeCode = null;
                $this->storeId = null;
            }
        }

        if ($website !== '') {
            try {
                $this->scopeCode = (string)$this->storeManager->getWebsite($website)->getCode();
                $this->scope = 'websites';

                return;
            } catch (\Exception $e) {
                $this->scopeCode = null;
            }
        }

        $this->scope = 'default';
        $this->scopeCode = null;
        $this->storeId = null;
    }
}
