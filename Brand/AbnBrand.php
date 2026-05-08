<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Brand;

use Two\Gateway\Api\BrandRegistryInterface;

class AbnBrand implements BrandRegistryInterface
{
    public function getProvider(): string
    {
        return 'ABN AMRO';
    }

    public function getProviderFullName(): string
    {
        return 'ABN AMRO Asset Based Finance N.V.';
    }

    public function getProductName(): string
    {
        return 'ABN AMRO Zakelijk op Rekening';
    }

    public function getCheckoutUrlTemplate(): string
    {
        return 'https://%s.achterafbetalen.abnamro.nl';
    }

    public function getAvailablePaymentTerms(): array
    {
        return [30, 60, 90];
    }

    public function getSurchargeFixedMax(): ?array
    {
        return ['amount' => 25.0, 'currency' => 'EUR'];
    }

    public function getBrandTag(): string
    {
        return 'achterafbetalen';
    }
}
