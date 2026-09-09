<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Model\Config\Backend;

use Magento\Config\Model\Config\Loader;
use Magento\Config\Model\Config\Reader\Source\Deployed\SettingChecker;
use Magento\Config\Model\Config\Structure;
use Magento\Config\Model\Config\Structure\Element\Field as StructureField;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Value;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Message\ManagerInterface as MessageManager;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Registry;
use Two\Gateway\Model\Config\Backend\PaymentTerms\OfferedTermsGuard;
use Two\Gateway\Model\Config\StoredTerm;

/**
 * Deprecated field carrying a legacy custom term: the stored value may be removed but never
 * replaced, folds into an offered term's checkbox where the merchant record offers it, and blocks
 * the save while it holds something that is not a number of days (ABN-522).
 */
class PaymentTermsCustomDays extends Value
{
    /** Sibling holding the term checkboxes, whose tick is the other half of the fold-in. */
    private const SIBLING = 'payment_terms';

    /** @var OfferedTermsGuard */
    private $offeredTerms;

    /** @var MessageManager */
    private $messageManager;

    /** @var SettingChecker */
    private $settingChecker;

    /** @var Structure */
    private $structure;

    /** @var Loader */
    private $configLoader;

    /** @var int|null term the fold-in cleared, held for the post-commit notice */
    private $foldedIn = null;

    public function __construct(
        Context $context,
        Registry $registry,
        ScopeConfigInterface $config,
        TypeListInterface $cacheTypeList,
        OfferedTermsGuard $offeredTerms,
        MessageManager $messageManager,
        SettingChecker $settingChecker,
        Structure $structure,
        Loader $configLoader,
        ?AbstractResource $resource = null,
        ?AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        parent::__construct($context, $registry, $config, $cacheTypeList, $resource, $resourceCollection, $data);
        $this->offeredTerms = $offeredTerms;
        $this->messageManager = $messageManager;
        $this->settingChecker = $settingChecker;
        $this->structure = $structure;
        $this->configLoader = $configLoader;
    }

    /**
     * @inheritDoc
     *
     * @throws LocalizedException when the post carries a value other than the stored one.
     */
    public function beforeSave()
    {
        $posted = trim((string)$this->getValue());
        $stored = trim($this->storedValue());

        if ($posted !== '' && $posted !== $stored) {
            throw new LocalizedException(__('Custom payment terms (days) can only be removed, not changed.'));
        }

        if (StoredTerm::isUnusable($posted)) {
            throw new LocalizedException(__(
                'Custom payment terms (days) holds "%1", which is not a usable number of days.'
                . ' Choose Remove on that field to clear it.',
                $posted
            ));
        }

        $days = StoredTerm::days($posted);
        if ($days !== null && $this->siblingTakesTheTerm() && $this->isOffered($days)) {
            $this->foldedIn = $days;
            $posted = '';
        }

        $this->setValue($posted);

        return parent::beforeSave();
    }

    /**
     * @inheritDoc
     *
     * Announced only once the whole config save has committed: the message queue is session-backed,
     * so a later field's refusal would otherwise report a clearing that rolled back.
     */
    public function afterCommitCallback()
    {
        if ($this->foldedIn !== null) {
            $this->messageManager->addNoticeMessage(__(
                'Custom payment terms (days) of %1 is now one of the standard terms you offer, so it has'
                . ' been selected under Payment terms and the custom field cleared.',
                $this->foldedIn
            ));
            $this->foldedIn = null;
        }

        return parent::afterCommitCallback();
    }

    /**
     * The value the form rendered — the config table row, not getOldValue()'s cached resolution
     * of the same path: a disagreement made the keep option unpostable (ABN-531).
     */
    private function storedValue(): string
    {
        $path = (string)$this->getPath();
        $separator = strrpos($path, '/');
        $rows = $separator === false ? [] : $this->configLoader->getConfigByPath(
            substr($path, 0, $separator),
            (string)$this->getScope() ?: 'default',
            (int)$this->getScopeId(),
            false
        );

        return (string)(array_key_exists($path, $rows) ? $rows[$path] : $this->getOldValue());
    }

    /**
     * Whether the sibling's matching tick will be in effect after this save. An `inherit` flag
     * means no value is written for it, and an env.php lock overrides whatever is. The posted
     * VALUE cannot answer either — the checkboxes template always emits an empty hidden fallback,
     * so an inheriting sibling posts '' rather than nothing.
     */
    private function siblingTakesTheTerm(): bool
    {
        $groups = $this->getData('groups');
        $posted = is_array($groups)
            ? ($groups[(string)$this->getData('group_id')]['fields'][self::SIBLING] ?? null)
            : null;
        if (!is_array($posted) || !empty($posted['inherit'])) {
            return false;
        }

        $sibling = $this->siblingConfigPath();
        if ($sibling === null) {
            return false;
        }

        return !$this->settingChecker->isReadOnly(
            $sibling,
            (string)$this->getScope(),
            $this->getScopeCode()
        );
    }

    /**
     * SettingChecker keys env.php locks by config path; the model carries its group's structure
     * path, which resolves to nothing there.
     */
    private function siblingConfigPath(): ?string
    {
        $groupStructurePath = $this->getData('field_config')['path'] ?? null;
        if (!is_string($groupStructurePath) || $groupStructurePath === '') {
            return null;
        }
        $sibling = $this->structure->getElement($groupStructurePath . '/' . self::SIBLING);
        $path = $sibling instanceof StructureField ? (string)$sibling->getConfigPath() : '';

        return $path === '' ? null : $path;
    }

    /**
     * An unresolvable offered set matches nothing rather than everything, so an API outage
     * cannot delete a migration value (ABN-493).
     */
    private function isOffered(int $days): bool
    {
        $offered = $this->offeredTerms->offered($this->resolveStoreId());

        return $offered !== [] && in_array($days, $offered, true);
    }

    /**
     * Store id for the scope being saved, or null for website/default —
     * the offered-terms lookup resolves the per-store API key from it.
     */
    private function resolveStoreId(): ?int
    {
        return $this->getScope() === 'stores' && (int)$this->getScopeId() > 0
            ? (int)$this->getScopeId()
            : null;
    }
}
