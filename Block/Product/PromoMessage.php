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
use Two\Gateway\Service\Merchant\ApiKeyStatus;

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
        private readonly ApiKeyStatus $apiKeyStatus,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * The merchant opts in, but a method the buyer cannot actually use must
     * never advertise itself.
     *
     * The api-key verdict is the same gate every other buyer-facing surface
     * applies (Model\Two::isAvailable, Model\Ui\ConfigProvider::getConfig,
     * Model\Webapi\OrderIntent::place, Model\Webapi\CompanyLookup). Only a
     * DEFINITIVE rejection withholds, so a transient outage leaves the message
     * up, exactly as it leaves the method on offer.
     *
     * This is not the full availability chain: minimum order value, buyer
     * country and currency all depend on a cart that does not exist on a
     * product page, so the message advertises that the method exists, never
     * that this buyer will be offered it.
     */
    public function isVisible(): bool
    {
        return $this->configRepository->isActive()
            && $this->configRepository->isProductMessageEnabled()
            && !$this->apiKeyStatus->isDefinitiveFailure()
            && $this->getMessage() !== '';
    }

    /**
     * Merchant override wins; otherwise the same phrase the checkout tile
     * already uses (Model/Ui/CheckoutTileCopy::getAboutTooltipHtml), so the
     * product page and checkout say the same thing and the wording is one
     * that shipped and was translated rather than written for this slot.
     * Magento keys translations on the source string, so it resolves against
     * the existing catalogue rows.
     *
     * It names no brand: the mark beside it does that, and repeating the name
     * in the text reads as duplication.
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

        return (string)__('Buy now, receive your goods, pay your invoice later.');
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

    /**
     * The accessible name for the mark.
     *
     * The mark is a CSS background, which carries no alternative text, and the
     * message names no brand — so without this a screen-reader user hears an
     * unattributed financing offer.
     */
    public function getBrandLabel(): string
    {
        return trim($this->brandRegistry->getProductName());
    }
}
