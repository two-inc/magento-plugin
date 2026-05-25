<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Plugin\Magento\Payment\Model\PaymentMethodList;

use Magento\Payment\Model\PaymentMethodList;
use Two\Gateway\Model\Brand\ActiveBrandResolver;
use Two\Gateway\Model\Brand\BrandPaymentMethodFactory;

/**
 * Appends the active brand's payment method to the lists Magento
 * builds from compiled payment.xml metadata. afterGetList covers
 * the generic-list path; afterGetActiveList covers the
 * payment/<code>/active=1 gated path used by checkout. The brand
 * instance produced here is the same one Helper\Data manufactures.
 */
class AppendBrands
{
    public function __construct(
        private readonly ActiveBrandResolver $activeBrandResolver,
        private readonly BrandPaymentMethodFactory $brandPaymentMethodFactory
    ) {
    }

    public function afterGetList(PaymentMethodList $subject, array $result, $storeId): array
    {
        return $this->merge($result);
    }

    public function afterGetActiveList(PaymentMethodList $subject, array $result, $storeId): array
    {
        $active = $this->activeBrandResolver->resolve();
        $instance = $this->brandPaymentMethodFactory->build($active);
        if (!$instance->isActive((int)$storeId)) {
            return $result;
        }
        return $this->merge($result);
    }

    private function merge(array $methods): array
    {
        $active = $this->activeBrandResolver->resolve();
        $instance = $this->brandPaymentMethodFactory->build($active);

        $byCode = [];
        foreach ($methods as $method) {
            $byCode[$method->getCode()] = $method;
        }
        $byCode[$active->getCode()] = $instance;

        return array_values($byCode);
    }
}
