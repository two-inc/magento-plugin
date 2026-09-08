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
use Two\Gateway\Model\Config\Backend\PaymentTerms\OfferedTermsGuard;

/**
 * Refuses a custom day the merchant does not offer (ABN-493); clears one that duplicates an offered term (TWO-25498).
 */
class PaymentTermsCustomDays extends Value
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
     */
    public function beforeSave()
    {
        $custom = (int)$this->getValue();
        if ($custom > 0) {
            $storeId = $this->resolveStoreId();
            if (in_array($custom, $this->offeredTerms->offered($storeId), true)) {
                $this->setValue('');
            } else {
                $this->offeredTerms->assertOffered([$custom], $storeId);
            }
        }

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
