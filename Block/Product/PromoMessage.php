<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Block\Product;

use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Two\Gateway\Api\BrandRegistryInterface;
use Two\Gateway\Api\Config\RepositoryInterface as ConfigRepository;

/**
 * Product detail page promotional message (TWO-25799).
 *
 * Opt-in and copy-only: it advertises that the method exists, it does not
 * claim this buyer or this basket will be approved. Availability at checkout
 * still depends on minimum order value, country, currency and surcharge
 * configuration, none of which is known on a product page.
 */
class PromoMessage extends Template
{
    public function __construct(
        Context $context,
        private readonly ConfigRepository $configRepository,
        private readonly BrandRegistryInterface $brandRegistry,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * Both gates matter: the merchant opts in, but a disabled payment method
     * must never advertise itself.
     */
    public function isVisible(): bool
    {
        return $this->configRepository->isActive()
            && $this->configRepository->isProductMessageEnabled()
            && $this->getMessage() !== '';
    }

    /**
     * Merchant override wins; otherwise the brand-aware default.
     *
     * TWO-25799: deliberately no day count. Terms run from fulfilment, not from
     * the page view, and the configured `payment_terms` list is intersected
     * with the merchant record's available_terms at
     * Model/Config/Repository::getPaymentTerms, so "30 days" here could
     * contradict what checkout offers. A merchant who knows their own terms
     * can still put a number in the override field.
     */
    public function getMessage(): string
    {
        $configured = trim($this->configRepository->getProductMessage());
        if ($configured !== '') {
            return $configured;
        }

        $provider = trim($this->brandRegistry->getProviderFullName());

        return $provider === '' ? '' : (string)__('Buy now, pay later with %1', $provider);
    }

    /**
     * The active brand's payment code, so CSS can pick the right mark.
     *
     * The mark is a CSS background keyed on this value rather than an image
     * this template hardcodes, matching how .two-payment-shield lets an
     * overlay supply its own. Shipping one unconditionally would show Two's
     * mark on a partner's storefront.
     */
    public function getBrandCode(): string
    {
        return $this->brandRegistry->getCode();
    }
}
