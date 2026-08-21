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
use Two\Gateway\Service\Merchant\SettingsProvider;

/**
 * Backend model for payment terms checkboxes.
 *
 * Converts the array of checked values into a comma-separated string
 * for storage, matching the existing multiselect format.
 */
class PaymentTermsCheckboxes extends Value
{
    /** @var SettingsProvider */
    private $settingsProvider;

    public function __construct(
        Context $context,
        Registry $registry,
        ScopeConfigInterface $config,
        TypeListInterface $cacheTypeList,
        SettingsProvider $settingsProvider,
        ?AbstractResource $resource = null,
        ?AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        parent::__construct($context, $registry, $config, $cacheTypeList, $resource, $resourceCollection, $data);
        $this->settingsProvider = $settingsProvider;
    }

    /**
     * @inheritDoc
     *
     * @throws LocalizedException when no payment term is selected and no custom
     *         term is entered — a selection is mandatory.
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

        // Fold the sibling custom-days field in when it duplicates a
        // merchant-offered term, ticked or not (TWO-25498) — comparing
        // against only the ticked subset left this branch unreachable on
        // an offered-but-unticked preset. getFieldsetDataValue() reads the
        // POSTED value, which Magento populates for the whole group before
        // any field's beforeSave() runs, so this does not depend on
        // save-execution order between the two fields.
        $custom = (int)$this->getFieldsetDataValue('payment_terms_duration_days');
        if ($custom > 0
            && !in_array($custom, $value, true)
            && in_array($custom, $this->settingsProvider->getAvailableTerms($this->resolveStoreId()), true)
        ) {
            $value[] = $custom;
        }
        sort($value);

        // A selection is mandatory. The optional custom term (sibling field)
        // also satisfies it, so a single off-preset term may be offered alone.
        if (count($value) === 0 && $custom <= 0) {
            throw new LocalizedException(
                __('Select at least one payment term or enter a custom term.')
            );
        }

        $this->setValue(implode(',', $value));
        return parent::beforeSave();
    }

    /**
     * Store id for the scope being saved, or null for website/default —
     * SettingsProvider resolves the per-store API key from it.
     */
    private function resolveStoreId(): ?int
    {
        return $this->getScope() === 'stores' && (int)$this->getScopeId() > 0
            ? (int)$this->getScopeId()
            : null;
    }
}
