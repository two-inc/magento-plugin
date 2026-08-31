<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Two\Gateway\Plugin\Config\Structure;

use Magento\Config\Model\Config\Structure\Element\Field;
use Magento\Framework\App\RequestInterface;
use Magento\Store\Model\StoreManagerInterface;
use Two\Gateway\Api\Config\RepositoryInterface as ConfigRepository;

/**
 * Hides the deprecated `default_shipping_tax_rate` admin field (superseded
 * by `default_shipping_tax_class`, TWO-25386) unless a value is already
 * stored at the scope being edited — the same hidden-unless-already-
 * configured carve-out `custom_surcharge_tax_rate` gets, applied directly
 * to this field via `Field::isVisible()` rather than through a sibling
 * selector's option list: unlike the surcharge pair, this field has no
 * sibling selector whose option list could carry the gate.
 *
 * Store-id resolution mirrors
 * Two\Gateway\Model\Config\Source\SurchargeTaxClass::resolveStoreId() —
 * the scope the admin form is editing, so the carve-out reflects the
 * value the merchant would actually inherit there.
 */
class HideDeprecatedShippingTaxRateField
{
    private const TARGET_FIELD = 'default_shipping_tax_rate';

    public function __construct(
        private readonly ConfigRepository $configRepository,
        private readonly RequestInterface $request,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * @param Field $subject
     * @param bool  $result Whatever Field::isVisible computed natively.
     * @return bool
     */
    public function afterIsVisible(Field $subject, $result)
    {
        if (!$result || $subject->getId() !== self::TARGET_FIELD) {
            return $result;
        }

        return $this->configRepository->getDefaultShippingTaxRate($this->resolveStoreId()) !== null;
    }

    private function resolveStoreId(): ?int
    {
        try {
            $storeCode = $this->request->getParam('store');
            if ($storeCode) {
                return (int)$this->storeManager->getStore($storeCode)->getId();
            }
            $websiteCode = $this->request->getParam('website');
            if ($websiteCode) {
                $website = $this->storeManager->getWebsite($websiteCode);
                $group = $this->storeManager->getGroup($website->getDefaultGroupId());
                $storeId = (int)$group->getDefaultStoreId();
                return $storeId > 0 ? $storeId : null;
            }
        } catch (\Exception $e) {
            return null;
        }
        return null;
    }
}
