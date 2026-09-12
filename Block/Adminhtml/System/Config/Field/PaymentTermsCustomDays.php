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
use Magento\Config\Model\Config\Structure;
use Magento\Config\Model\Config\Structure\Element\Field as StructureField;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Store\Model\StoreManagerInterface;
use Two\Gateway\Model\Config\AdminScope;
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

    /** @var Structure */
    private $structure;

    /** @var string|null */
    private $scope;

    /** @var string|null */
    private $scopeCode;

    /** @var int|null */
    private $scopeId;

    public function __construct(
        Context $context,
        OfferedTermsGuard $offeredTerms,
        StoreManagerInterface $storeManager,
        SettingChecker $settingChecker,
        Structure $structure,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->offeredTerms = $offeredTerms;
        $this->storeManager = $storeManager;
        $this->settingChecker = $settingChecker;
        $this->structure = $structure;
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
        [$scopeId, $scope] = $this->resolveMerchantScope();
        $offered = $this->offeredTerms->offered($scopeId, $scope);

        return $offered !== [] && in_array($days, $offered, true)
            ? '<span class="two-legacy-term-folds-in" hidden="hidden"></span>'
            : '';
    }

    /**
     * An env.php lock overrides whatever the sibling's own save writes, so the tick never takes
     * effect and folding the value away would lose the term. The sibling's inherit state is only
     * known in the browser, so the JS composes that half with this marker.
     */
    private function siblingCanTakeTheTerm(AbstractElement $element): bool
    {
        $sibling = $this->siblingConfigPath($element->getData('field_config')['path'] ?? null);
        if ($sibling === null) {
            return false;
        }
        $this->resolveScope();

        return !$this->settingChecker->isReadOnly($sibling, (string)$this->scope, $this->scopeCode);
    }

    /**
     * SettingChecker keys env.php locks by config path; the element carries its group's structure
     * path, which resolves to nothing there.
     *
     * @param mixed $groupStructurePath
     */
    private function siblingConfigPath($groupStructurePath): ?string
    {
        if (!is_string($groupStructurePath) || $groupStructurePath === '') {
            return null;
        }
        $sibling = $this->structure->getElement($groupStructurePath . '/' . self::SIBLING);
        $path = $sibling instanceof StructureField ? (string)$sibling->getConfigPath() : '';

        return $path === '' ? null : $path;
    }

    /**
     * Scope being edited, as the config repository reads it (ABN-530).
     *
     * @return array{int|null, string}
     */
    private function resolveMerchantScope(): array
    {
        $this->resolveScope();

        return AdminScope::fromScope($this->scope, $this->scopeId);
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
                $this->scopeId = (int)$resolved->getId() ?: null;
                $this->scope = 'stores';

                return;
            } catch (\Exception $e) {
                $this->scopeCode = null;
                $this->scopeId = null;
            }
        }

        if ($website !== '') {
            try {
                $resolved = $this->storeManager->getWebsite($website);
                $this->scopeCode = (string)$resolved->getCode();
                $this->scopeId = (int)$resolved->getId() ?: null;
                $this->scope = 'websites';

                return;
            } catch (\Exception $e) {
                $this->scopeCode = null;
                $this->scopeId = null;
            }
        }

        $this->scope = 'default';
        $this->scopeCode = null;
        $this->scopeId = null;
    }
}
