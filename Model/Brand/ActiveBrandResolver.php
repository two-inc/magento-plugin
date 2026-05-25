<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Model\Brand;

/**
 * Resolves the single active brand for this install.
 *
 * Invariant: max one overlay brand atop Two.
 *   - Two only          → Two is active.
 *   - Two + one overlay → overlay is active.
 *   - Three+ brands     → throws DomainException at first resolve().
 *
 * Reversible to a multi-brand registry by rewriting this class
 * alone if business ever needs multi-brand-same-install.
 */
class ActiveBrandResolver
{
    public const TWO_CODE = 'two_payment';

    private ?Descriptor $active = null;

    public function __construct(
        private readonly Loader $loader
    ) {
    }

    public function resolve(): Descriptor
    {
        if ($this->active !== null) {
            return $this->active;
        }

        $brands = $this->loader->load();

        if ($brands === []) {
            throw new \DomainException(
                'No brands registered. magento-plugin must ship its own etc/brand.xml.'
            );
        }

        $overlays = array_filter(
            $brands,
            static fn(Descriptor $b) => $b->getCode() !== self::TWO_CODE
        );

        if (count($overlays) > 1) {
            throw new \DomainException(sprintf(
                'Multiple overlay brands installed: %s. '
                . 'Only one overlay is supported per install.',
                implode(', ', array_map(static fn(Descriptor $b) => $b->getCode(), $overlays))
            ));
        }

        if ($overlays === []) {
            if (!isset($brands[self::TWO_CODE])) {
                throw new \DomainException(sprintf(
                    'Two brand ("%s") not declared in any brand.xml; '
                    . 'magento-plugin/etc/brand.xml is missing.',
                    self::TWO_CODE
                ));
            }
            return $this->active = $brands[self::TWO_CODE];
        }

        return $this->active = reset($overlays);
    }

    /**
     * @return array<string,Descriptor> All registered brands keyed by code.
     */
    public function all(): array
    {
        return $this->loader->load();
    }
}
