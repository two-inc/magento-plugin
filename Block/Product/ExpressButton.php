<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Block\Product;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Registry;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Two\Gateway\Api\BrandRegistryInterface;
use Two\Gateway\Api\Config\RepositoryInterface as ConfigRepository;
use Two\Gateway\Controller\Express\Confirm;
use Two\Gateway\Service\Merchant\ApiKeyStatus;

/**
 * Product detail page "Buy with <brand>" button (TWO-25800).
 *
 * Adds THIS item to the basket and sends the buyer to checkout with the
 * method already selected. It does not place an order and it does not need
 * one to exist: everything the order payload requires (company identity,
 * addresses, final totals) is still collected by the normal checkout.
 *
 * Opt-in, and a separate switch from the promotional message (TWO-25799), so
 * a shop may run either, both or neither.
 */
class ExpressButton extends Template
{

    public function __construct(
        Context $context,
        private readonly ConfigRepository $configRepository,
        private readonly BrandRegistryInterface $brandRegistry,
        private readonly ApiKeyStatus $apiKeyStatus,
        private readonly Registry $registry,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * Same three gates the promotional message applies, for the same reasons.
     *
     * Only a DEFINITIVE api-key rejection withholds, so a transient outage
     * leaves the button up exactly as it leaves the method on offer.
     *
     * This is deliberately NOT the full availability chain. Minimum order
     * value, buyer country and currency all need a cart, which a product page
     * has not got, so the button cannot promise the method will be offered at
     * checkout. When it is not, the checkout component simply never finds it
     * in the available list and selects nothing, leaving the buyer to choose
     * as they would have anyway.
     *
     * A brand name is required: without it the control would be an
     * unattributed purchase button next to add to cart.
     */
    public function isVisible(): bool
    {
        try {
            if (!$this->configRepository->isActive() || !$this->configRepository->isProductButtonEnabled()) {
                return false;
            }
        } catch (LocalizedException $e) {
            // An unrecognised stored value raises at the read, per the module's
            // fail-loud standard. This is the gate that catches it: the button
            // is withheld and the product page renders, which is the same shape
            // as the availability gate withdrawing the method and nothing else.
            // The repository has already reported the offending value.
            return false;
        }

        return !$this->apiKeyStatus->isDefinitiveFailure()
            && $this->isProductPurchasable()
            && $this->getBrandLabel() !== '';
    }

    /**
     * The button submits the product's own add-to-cart form, so it must not
     * appear where that form cannot succeed.
     *
     * product.info.main renders for a disabled or out-of-stock product even
     * though Magento suppresses its native Add to Cart there, so without this
     * the storefront offers "Buy with <brand>" on something nobody can buy and
     * sends the buyer into an add that fails.
     *
     * No product resolvable means this is not a product page the button
     * belongs on, so it withholds rather than guessing.
     */
    private function isProductPurchasable(): bool
    {
        $product = $this->registry->registry('current_product');

        if (!$product instanceof ProductInterface || !method_exists($product, 'isSalable')) {
            return false;
        }

        // isSalable() covers disabled, out of stock, and a configurable whose
        // children are all unavailable.
        return (bool)$product->isSalable();
    }

    /**
     * The visible words, which do not name the brand: the mark does that.
     *
     * A fragment rather than a sentence, because a mark follows it — the
     * translator comment on the catalogue entry says so.
     */
    public function getLabel(): string
    {
        // translators: the payment brand's mark follows this text, so the
        // control reads "Buy with <brand>". Not a complete sentence.
        return (string)__('Buy with');
    }

    /**
     * The brand's customer-facing name.
     *
     * Always emitted, and never inside an aria-hidden element, because it is
     * what puts the brand into the button's accessible name once the visible
     * word is gone. A brand that paints a mark hides this from SIGHT in its own
     * CSS and keeps it in the accessibility tree; a brand that ships no mark
     * leaves it visible, so no storefront has a purchase control that names no
     * brand.
     */
    public function getBrandLabel(): string
    {
        return trim($this->brandRegistry->getProductName());
    }

    /**
     * The active brand's payment code, so CSS can key the mark on it rather
     * than this template shipping one brand's image to every storefront.
     */
    public function getBrandCode(): string
    {
        return $this->brandRegistry->getCode();
    }

    /**
     * Where core sends the buyer once it has dealt with the add.
     *
     * NOT checkout directly. Core applies this URL on the success path and on
     * two exceptional failure paths as well - the generic `catch (\Exception)`
     * and the `if (!$product)` guard, both of which reach `goBack()` with no URL
     * of their own - so a URL pointing straight at checkout would take a buyer
     * there whose item never made it, with core's own explanation cleared on the
     * way by `getBackUrl()`.
     *
     * So it points at our confirmation controller, which asks the quote what
     * actually happened and only then hands them on. The product id rides with
     * it because that is what the controller looks for; it is a plain id, and
     * every destination is resolved from it server-side, so nothing here can be
     * turned into an open redirect.
     */
    public function getReturnUrl(): string
    {
        return $this->getUrl(
            'two/express/confirm',
            ['_query' => [Confirm::PRODUCT_PARAM => $this->getProductId()]]
        );
    }

    /**
     * The product this button belongs to, already resolved from the registry by
     * the saleability gate above.
     */
    private function getProductId(): int
    {
        $product = $this->registry->registry('current_product');

        return $product instanceof ProductInterface ? (int)$product->getId() : 0;
    }
}
