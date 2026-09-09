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
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Registry;
use Two\Gateway\Model\Config\Backend\PaymentTerms\OfferedTermsGuard;
use Two\Gateway\Model\Config\StoredTerm;

/**
 * Backend model for payment terms checkboxes.
 *
 * Converts the array of checked values into a comma-separated string
 * for storage, matching the existing multiselect format.
 */
class PaymentTermsCheckboxes extends Value
{
    private $offeredTerms;

    public function __construct(
        Context $context,
        Registry $registry,
        ScopeConfigInterface $config,
        TypeListInterface $cacheTypeList,
        OfferedTermsGuard $offeredTerms,
        ?AbstractResource $resource = null,
        ?AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        parent::__construct($context, $registry, $config, $cacheTypeList, $resource, $resourceCollection, $data);
        $this->offeredTerms = $offeredTerms;
    }

    /**
     * @inheritDoc
     *
     * @throws LocalizedException when a selected term is not offered, or nothing is selected.
     */
    public function beforeSave()
    {
        $raw = $this->getValue();
        if (is_array($raw)) {
            $value = array_filter(array_map('intval', $raw));
        } else {
            // CSV string (e.g. a CLI config:set) — normalise identically.
            $value = array_filter(array_map('intval', explode(',', (string)$raw)));
        }

        $storeId = $this->resolveStoreId();
        $this->offeredTerms->assertOffered($value, $storeId);

        // fieldset_data holds the whole group before any beforeSave() runs, so sibling reads are order-independent (TWO-25498).
        $custom = StoredTerm::days($this->getFieldsetDataValue('payment_terms_duration_days'));

        // The deprecated sibling clears a value the merchant record offers as a standard term; it
        // belongs on that term's checkbox instead. An unresolvable offered set matches nothing, so
        // an API outage cannot move a migration value either (ABN-493, ABN-522).
        $offered = $this->offeredTerms->offered($storeId);
        if ($custom !== null
            && $offered !== []
            && in_array($custom, $offered, true)
            && !in_array($custom, $value, true)
        ) {
            $value[] = $custom;
        }
        sort($value);

        // A selection is mandatory; the sibling custom-days field satisfies it too.
        if (count($value) === 0 && $custom === null) {
            throw new LocalizedException(
                __('Select at least one payment term or enter a custom term.')
            );
        }

        $this->setValue(implode(',', $value));
        return parent::beforeSave();
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
