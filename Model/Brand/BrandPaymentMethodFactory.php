<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Model\Brand;

use Magento\Framework\ObjectManagerInterface;
use Two\Gateway\Api\BrandRegistryInterface;
use Two\Gateway\Model\GenericPaymentMethod;

/**
 * Manufactures GenericPaymentMethod instances bound to a given
 * Descriptor. Owns a per-request cache keyed by brand code —
 * Magento's Helper\Data::$_methodInstances cache is NOT populated
 * when our around-plugin short-circuits $proceed (the parent's
 * cache-write line lives in the original method body), so we
 * cache here instead.
 *
 * Singleton-scoped via DI lifetime; for CLI/consumer processes
 * the cache persists for the process lifetime, which is fine
 * because Descriptors are immutable per process.
 *
 * v6 PR A: passes the Descriptor's code as the legacy `code`
 * argument and the DI-resolved BrandRegistryInterface (currently
 * DescriptorBackedBrandRegistry) as the `brand` argument. PR B
 * replaces both with a single `descriptor` constructor arg.
 */
class BrandPaymentMethodFactory
{
    /** @var array<string,GenericPaymentMethod> */
    private array $instances = [];

    public function __construct(
        private readonly ObjectManagerInterface $objectManager,
        private readonly BrandRegistryInterface $brandRegistry
    ) {
    }

    public function build(Descriptor $descriptor): GenericPaymentMethod
    {
        $code = $descriptor->getCode();
        if (isset($this->instances[$code])) {
            return $this->instances[$code];
        }

        /** @var GenericPaymentMethod $instance */
        $instance = $this->objectManager->create(
            GenericPaymentMethod::class,
            [
                'code' => $code,
                'brand' => $this->brandRegistry,
            ]
        );

        return $this->instances[$code] = $instance;
    }
}
