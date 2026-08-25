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
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Registry;
use Two\Gateway\Service\Merchant\SettingsProvider;

/**
 * Backend model for the "Custom Payment Terms (days)" field.
 *
 * A custom value that now duplicates one of the merchant's backend-offered
 * terms (SettingsProvider::getAvailableTerms(), regardless of whether that
 * term is currently ticked — see this field's visibility logic in
 * payment-terms-config.js) is redundant: clear it here. The matching
 * fold-in — ticking that term's checkbox — lives on the sibling
 * PaymentTermsCheckboxes backend model, which reads this field's posted
 * value via getFieldsetDataValue(). That is order-independent of Magento's
 * per-field save sequencing: Magento populates fieldset_data for the whole
 * group before any field in it runs beforeSave() (TWO-25498).
 */
class PaymentTermsCustomDays extends Value
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
     */
    public function beforeSave()
    {
        $custom = (int)$this->getValue();
        if ($custom > 0 && in_array($custom, $this->settingsProvider->getAvailableTerms($this->resolveStoreId()), true)) {
            $this->setValue('');
        }

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
