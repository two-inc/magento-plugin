<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Plugin\Magento\Payment\Helper\Data;

use Magento\Payment\Helper\Data as PaymentHelper;
use Magento\Payment\Model\MethodInterface;
use Two\Gateway\Model\Brand\ActiveBrandResolver;
use Two\Gateway\Model\Brand\BrandPaymentMethodFactory;

/**
 * Manufactures a GenericPaymentMethod for the active brand's code
 * before Magento consults the CCD-stored model FQCN. The CCD
 * payment/<code>/model row is read by Magento but never used by
 * our path — we short-circuit $proceed.
 *
 * Belt: on non-active codes (or after uninstall of the brand
 * module), $proceed runs Magento's normal path, which may
 * autoload-fail on an orphan FQCN. Catching \Error returns null,
 * matching Magento's documented "method not found" contract for
 * downstream consumers (PaymentMethodList::getActiveList already
 * filters null entries).
 */
class InterceptGetMethodInstance
{
    public function __construct(
        private readonly ActiveBrandResolver $activeBrandResolver,
        private readonly BrandPaymentMethodFactory $brandPaymentMethodFactory
    ) {
    }

    /**
     * @param PaymentHelper $subject
     * @param callable $proceed
     * @param string $code
     * @return MethodInterface|null
     */
    public function aroundGetMethodInstance(
        PaymentHelper $subject,
        callable $proceed,
        $code
    ) {
        $active = $this->activeBrandResolver->resolve();
        if ($code === $active->getCode()) {
            return $this->brandPaymentMethodFactory->build($active);
        }

        try {
            return $proceed($code);
        } catch (\Error $e) {
            return null;
        }
    }
}
