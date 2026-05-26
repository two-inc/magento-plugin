<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Model;

use Two\Gateway\Api\BrandRegistryInterface;

/**
 * Value-object implementation of BrandRegistryInterface, intended to
 * be configured via DI virtualType in brand-overlay packages.
 *
 * Brand overlays declare a virtualType of `Two\Gateway\Model\Brand`
 * with their own provider / productName / checkoutUrlTemplate / etc
 * constructor arguments, then bind the BrandRegistryInterface
 * preference to that virtualType to inject brand-specific values
 * throughout the gateway.
 *
 * The default Two binding lives at Two\Gateway\Brand\TwoBrand and
 * does not use this class — it returns its values directly from
 * hardcoded methods. This class exists to let downstream brand
 * overlays declare a brand without writing a new PHP class for it.
 */
class Brand implements BrandRegistryInterface
{
    /** @var string */
    private $provider;
    /** @var string */
    private $providerFullName;
    /** @var string */
    private $productName;
    /** @var string */
    private $checkoutUrlTemplate;
    /** @var int[] */
    private $availablePaymentTerms;
    /** @var array{amount: float, currency: string}|null */
    private $surchargeFixedMax;
    /** @var string */
    private $signUpUrl;
    /** @var string */
    private $documentationUrl;
    /** @var string */
    private $brandTag;

    /**
     * @param int[] $availablePaymentTerms
     * @param array{amount: float, currency: string}|null $surchargeFixedMax
     */
    public function __construct(
        string $provider,
        string $providerFullName,
        string $productName,
        string $checkoutUrlTemplate,
        array $availablePaymentTerms,
        ?array $surchargeFixedMax = null,
        string $signUpUrl = '',
        string $documentationUrl = '',
        string $brandTag = ''
    ) {
        $this->provider = $provider;
        $this->providerFullName = $providerFullName;
        $this->productName = $productName;
        $this->checkoutUrlTemplate = $checkoutUrlTemplate;
        $this->availablePaymentTerms = $availablePaymentTerms;
        $this->surchargeFixedMax = $surchargeFixedMax;
        $this->signUpUrl = $signUpUrl;
        $this->documentationUrl = $documentationUrl;
        $this->brandTag = $brandTag;
    }

    public function getProvider(): string
    {
        return $this->provider;
    }

    public function getProviderFullName(): string
    {
        return $this->providerFullName;
    }

    public function getProductName(): string
    {
        return $this->productName;
    }

    public function getCheckoutUrlTemplate(): string
    {
        return $this->checkoutUrlTemplate;
    }

    public function getAvailablePaymentTerms(): array
    {
        return $this->availablePaymentTerms;
    }

    public function getSurchargeFixedMax(): ?array
    {
        return $this->surchargeFixedMax;
    }

    public function getSignUpUrl(): string
    {
        return $this->signUpUrl;
    }

    public function getDocumentationUrl(): string
    {
        return $this->documentationUrl;
    }

    public function getBrandTag(): string
    {
        return $this->brandTag;
    }

    /**
     * @deprecated 2.0.0 This class is the virtualType base for the
     *             legacy `AbnBrand` DI rebinding. After the brand-aware
     *             runtime-resolution work landed (Two\Gateway\Brand\
     *             DescriptorBackedBrandRegistry wired as the
     *             BrandRegistryInterface preference), nothing consumes
     *             this surface — the constructor is no longer reached
     *             on a vanilla install, and brand overlays have
     *             migrated to brand.xml-declared identity.
     */
    public function getCode(): string
    {
        throw new \LogicException(
            'Two\\Gateway\\Model\\Brand is deprecated; consume '
            . 'BrandRegistryInterface via DescriptorBackedBrandRegistry instead. '
            . 'The brand code now lives in brand.xml and is resolved at request '
            . 'time via ActiveBrandResolver.'
        );
    }

    /**
     * @deprecated 2.0.0 See note on getCode().
     */
    public function getModuleLabelChain(): array
    {
        throw new \LogicException(
            'Two\\Gateway\\Model\\Brand is deprecated; consume '
            . 'BrandRegistryInterface via DescriptorBackedBrandRegistry instead. '
            . 'Version-panel rows now come from brand.xml `<module_label_chain>` '
            . 'via ActiveBrandResolver.'
        );
    }

    /**
     * @deprecated 2.0.0 See note on getCode().
     */
    public function getInlineTermFees(): bool
    {
        throw new \LogicException(
            'Two\\Gateway\\Model\\Brand is deprecated; consume '
            . 'BrandRegistryInterface via DescriptorBackedBrandRegistry instead. '
            . 'Inline-term-fees flag now comes from brand.xml `<inline_term_fees>` '
            . 'via ActiveBrandResolver.'
        );
    }
}
