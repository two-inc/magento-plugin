<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Model\Config\Backend;

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
 * Deprecated field, retained only to carry a legacy custom term through an upgrade: the stored
 * value may be removed but never replaced, folds into an offered term's checkbox once the
 * merchant record offers it, and blocks the save while it holds something that is not a number
 * of days (ABN-522).
 */
class PaymentTermsCustomDays extends Value
{
    /** @var OfferedTermsGuard */
    private $offeredTerms;

    /** @var MessageManager */
    private $messageManager;

    public function __construct(
        Context $context,
        Registry $registry,
        ScopeConfigInterface $config,
        TypeListInterface $cacheTypeList,
        OfferedTermsGuard $offeredTerms,
        MessageManager $messageManager,
        ?AbstractResource $resource = null,
        ?AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        parent::__construct($context, $registry, $config, $cacheTypeList, $resource, $resourceCollection, $data);
        $this->offeredTerms = $offeredTerms;
        $this->messageManager = $messageManager;
    }

    /**
     * @inheritDoc
     *
     * @throws LocalizedException when the post carries a value other than the stored one.
     */
    public function beforeSave()
    {
        $posted = trim((string)$this->getValue());
        $stored = trim((string)$this->getOldValue());

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
        if ($days !== null && $this->isOffered($days)) {
            $this->messageManager->addNoticeMessage(__(
                'Custom payment terms (days) of %1 is now one of the standard terms you offer, so it has'
                . ' been selected under Payment terms and the custom field cleared.',
                $days
            ));
            $posted = '';
        }

        $this->setValue($posted);

        return parent::beforeSave();
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
