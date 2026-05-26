<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Brand;

use Two\Gateway\Api\BrandRegistryInterface;
use Two\Gateway\Model\Brand\ActiveBrandResolver;

/**
 * Bridge between the brand.xml-sourced Descriptor and the legacy
 * BrandRegistryInterface still consumed by Two, Adapter, Repository,
 * controllers and several admin blocks.
 *
 * The legacy interface is scheduled for deletion; until every
 * consumer migrates to Descriptor-direct, this adapter lets brand
 * identity flow from brand.xml through the existing API.
 */
class DescriptorBackedBrandRegistry implements BrandRegistryInterface
{
    public function __construct(
        private readonly ActiveBrandResolver $activeBrandResolver
    ) {
    }

    public function getProvider(): string
    {
        return $this->activeBrandResolver->resolve()->getProvider();
    }

    public function getProviderFullName(): string
    {
        return $this->activeBrandResolver->resolve()->getProviderFullName();
    }

    public function getProductName(): string
    {
        return $this->activeBrandResolver->resolve()->getProductName();
    }

    public function getCheckoutUrlTemplate(): string
    {
        return $this->activeBrandResolver->resolve()->getCheckoutUrlTemplate();
    }

    public function getAvailablePaymentTerms(): array
    {
        return $this->activeBrandResolver->resolve()->getAvailablePaymentTerms();
    }

    public function getSurchargeFixedMax(): ?array
    {
        return $this->activeBrandResolver->resolve()->getSurchargeFixedMax();
    }

    public function getSignUpUrl(): string
    {
        return $this->activeBrandResolver->resolve()->getSignUpUrl();
    }

    public function getDocumentationUrl(): string
    {
        return $this->activeBrandResolver->resolve()->getDocumentationUrl();
    }

    public function getBrandTag(): string
    {
        return $this->activeBrandResolver->resolve()->getBrandTag();
    }

    public function getCode(): string
    {
        return $this->activeBrandResolver->resolve()->getCode();
    }

    public function getInlineTermFees(): bool
    {
        return $this->activeBrandResolver->resolve()->getInlineTermFees();
    }

    public function getModuleLabelChain(): array
    {
        $chain = [];
        foreach ($this->activeBrandResolver->resolve()->getModuleLabelChain() as $row) {
            $chain[$row['label']] = $row['module'];
        }
        return $chain;
    }
}
