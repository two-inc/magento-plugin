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
use Two\Gateway\Api\BrandRegistryInterface;

/**
 * Merchant-set minimum order value. The platform/partner minimum (the
 * brand's minimum_order) is the floor: a merchant may only RAISE the
 * bar, never lower it below what the funding setup requires. The value
 * is interpreted in the platform minimum's currency and basis when one
 * exists, otherwise in the store base currency, gross.
 */
class MerchantMinimumOrder extends Value
{
    /**
     * @var BrandRegistryInterface
     */
    private $brandRegistry;

    public function __construct(
        Context $context,
        Registry $registry,
        ScopeConfigInterface $config,
        TypeListInterface $cacheTypeList,
        BrandRegistryInterface $brandRegistry,
        ?AbstractResource $resource = null,
        ?AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        $this->brandRegistry = $brandRegistry;
        parent::__construct($context, $registry, $config, $cacheTypeList, $resource, $resourceCollection, $data);
    }

    /**
     * @inheritDoc
     */
    public function beforeSave()
    {
        $value = trim((string)$this->getValue());
        if ($value === '') {
            return parent::beforeSave();
        }

        $normalised = str_replace(',', '.', $value);
        if (!is_numeric($normalised) || (float)$normalised < 0) {
            throw new LocalizedException(__('Minimum Order Value must be a non-negative number.'));
        }
        $this->setValue($normalised);

        $platformMinimum = $this->brandRegistry->getMinimumOrder();
        if ($platformMinimum !== null && (float)$normalised <= $platformMinimum['amount']) {
            throw new LocalizedException(__(
                'Minimum Order Value must exceed the platform minimum of %1 %2 (%3 tax).',
                $platformMinimum['amount'],
                $platformMinimum['currency'],
                $platformMinimum['basis'] === 'gross' ? __('including') : __('excluding')
            ));
        }

        return parent::beforeSave();
    }
}
