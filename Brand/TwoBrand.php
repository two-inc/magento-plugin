<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Brand;

use Two\Gateway\Api\BrandRegistryInterface;

class TwoBrand implements BrandRegistryInterface
{
    public function getProvider(): string
    {
        return 'Two';
    }

    public function getProviderFullName(): string
    {
        return 'Two';
    }

    public function getProductName(): string
    {
        return 'Two';
    }

    public function getCheckoutUrlTemplate(): string
    {
        return 'https://%s.two.inc';
    }

    public function getAvailablePaymentTerms(): array
    {
        return [14, 30, 60, 90];
    }

    public function getSurchargeFixedMax(): ?array
    {
        // No upper bound for Two — calling code must treat null as
        // "any positive value is fine" and skip the max validation.
        return null;
    }

    public function getBrandTag(): string
    {
        // Empty: the canonical Two checkout host (*.two.inc) already
        // identifies the brand at the URL level, and the Two checkout
        // page has not historically been called with a `?brand=two`
        // query parameter — emitting one risks the receiving renderer
        // hitting an "unknown brand" branch. Brand implementations
        // whose checkout shares a host with siblings may override
        // this to return a disambiguator tag.
        return '';
    }
}
