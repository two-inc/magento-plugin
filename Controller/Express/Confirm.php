<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Controller\Express;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Message\ManagerInterface as MessageManager;
use Magento\Framework\App\RequestInterface;
use Throwable;
use Two\Gateway\Api\BrandRegistryInterface;
use Two\Gateway\Api\Log\RepositoryInterface as LogRepository;

/**
 * Confirms that the product-page button's add actually happened, and only then
 * hands the buyer to checkout with the express marker (TWO-25800).
 *
 * The outcome of an add-to-cart is not in its response. Magento's widget fires
 * its event from the success callback whatever the body says, and
 * `Checkout\Controller\Cart\Add` ends both its success and its failure paths at
 * `goBack()`, which emits only `backUrl`. So nothing is inferred here: the quote
 * is the record of what happened, and it is asked directly.
 *
 * The controller is the `return_url` core redirects to, which core applies on
 * the success path AND on two exceptional failure paths — the generic
 * `catch (\Exception)` and the `if (!$product)` guard, both of which call
 * `goBack()` with no URL of their own. Those two are the whole reason this is
 * server-side: on them `Cart::getBackUrl()` also calls
 * `$this->messageManager->getMessages()->clear()`, so the buyer would otherwise
 * arrive at checkout with the item missing and core's explanation discarded.
 *
 * A buyer whose add failed therefore ends up where core's own Add to Cart would
 * have left them — back at the product, with a reason — rather than a step
 * further on with less information.
 */
class Confirm implements HttpGetActionInterface
{
    /** Matches the parameter Block\Product\ExpressButton puts on the return URL. */
    public const PRODUCT_PARAM = 'product';

    /** This controller's route, so the observer can recognise our own adds. */
    public const ROUTE = 'two/express/confirm';

    /** Names ONE attempt; see the mintToken() comment on the button's JS. */
    public const TOKEN_PARAM = 't';

    /**
     * Where Observer\ExpressAddSucceeded records the tokens of the attempts
     * that actually put something in the basket, as a set keyed by token.
     *
     * A set rather than one slot because a session is not one tab. Two express
     * adds running at once both complete against the same session, and with a
     * single slot the second overwrites the first: the first confirmation then
     * finds a token that is not its own, and clearing the slot on that read
     * takes the second attempt's stamp with it. Both buyers are sent back as
     * though the add had failed, with both items sitting in the basket.
     */
    public const SESSION_KEY = 'two_express_completed_token';

    /**
     * How many completions the set holds.
     *
     * An attempt whose tab is closed between the add and the confirmation
     * leaves its stamp behind and nothing else removes it, so the set is
     * bounded rather than left to grow for the life of the session. A stamp is
     * only wanted for the moment between the add completing and core's
     * redirect arriving here, so the oldest are the safe ones to drop.
     */
    public const MAX_STAMPS = 20;

    /**
     * Read by view/frontend/web/js/view/checkout/preselect.js. Declared here
     * because this controller is the only thing that emits it — it is applied
     * once the basket has been confirmed, never by the page that renders the
     * button.
     */
    public const EXPRESS_PARAM = 'two_express';

    public function __construct(
        private readonly RequestInterface $request,
        private readonly RedirectFactory $redirectFactory,
        private readonly CheckoutSession $checkoutSession,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly MessageManager $messageManager,
        private readonly LogRepository $logRepository,
        private readonly BrandRegistryInterface $brandRegistry
    ) {
    }

    /**
     * @return Redirect
     */
    public function execute()
    {
        $productId = (int)$this->request->getParam(self::PRODUCT_PARAM);

        try {
            if (!$this->attemptReachedTheBasket()) {
                return $this->backToProduct($productId);
            }
        } catch (Throwable $e) {
            // Deciding this is what the controller is FOR, so a failure to
            // decide must not stand in for an answer. Falling through to
            // checkout keeps a buyer whose add really did succeed from being
            // turned away by our own fault; the basket they arrive with is the
            // truth either way, and checkout shows it to them.
            $this->logRepository->addDebugLog(
                sprintf(
                    '%s express confirmation could not read the quote; continuing to checkout',
                    $this->brandRegistry->getCode()
                ),
                ['product_id' => $productId, 'exception' => $e->getMessage()]
            );
        }

        return $this->redirectFactory->create()->setPath(
            'checkout',
            ['_query' => [self::EXPRESS_PARAM => (string)$this->request->getParam(self::TOKEN_PARAM)]]
        );
    }

    /**
     * Whether THIS attempt reached the basket.
     *
     * The attempt carries a token, `Observer\ExpressAddSucceeded` stamps that
     * token when core reports the add complete, and the answer is whether the
     * two agree. Core dispatches `checkout_cart_add_product_complete` only
     * after the cart has saved, so the stamp is a statement about a real add;
     * the token is what makes it a statement about the add being asked about
     * rather than any add that happened to occur.
     *
     * THIS attempt's stamp is removed on a match, so one completion cannot
     * confirm twice — and only that one, so a confirmation that finds nothing
     * of its own leaves every other tab's completion where it is. Clearing the
     * whole record on a miss is what made one tab's arrival destroy another's.
     *
     * Nothing about the product is compared: with the attempt identified there
     * is no need, which is also why a grouped product — whose own id never
     * reaches the quote — needs no special case any more.
     */
    private function attemptReachedTheBasket(): bool
    {
        $token = (string)$this->request->getParam(self::TOKEN_PARAM);
        $stamps = $this->checkoutSession->getData(self::SESSION_KEY);

        if ($token === '' || !is_array($stamps)) {
            return false;
        }

        $remaining = [];
        $matched = false;

        foreach ($stamps as $stamped => $ignored) {
            // A key PHP stored as an int — a token of digits alone — compares
            // as the string it was written as.
            if (!$matched && hash_equals((string)$stamped, $token)) {
                $matched = true;

                continue;
            }

            $remaining[$stamped] = true;
        }

        if ($matched) {
            $this->checkoutSession->setData(self::SESSION_KEY, $remaining ?: null);
        }

        return $matched;
    }

    /**
     * Back where core would have left them, with the reason core discarded.
     *
     * The wording is core's own generic add failure, because the specific
     * message core queued was cleared by `getBackUrl()` before this controller
     * was reached and cannot be recovered. Generic and present beats specific
     * and gone.
     *
     * The destination is resolved from the product server-side rather than from
     * anything the URL carries, so this cannot be turned into an open redirect.
     * A product that will not load falls back to the cart, where the buyer can
     * see for themselves what they have.
     */
    private function backToProduct(int $productId): Redirect
    {
        $this->messageManager->addErrorMessage(
            __('We can\'t add this item to your shopping cart right now.')
        );

        $this->logRepository->addDebugLog(
            sprintf(
                '%s express add did not reach the basket; buyer returned to the product',
                $this->brandRegistry->getCode()
            ),
            ['product_id' => $productId]
        );

        try {
            $url = (string)$this->productRepository->getById($productId)->getProductUrl();
            if ($url !== '') {
                return $this->redirectFactory->create()->setUrl($url);
            }
        } catch (Throwable $e) {
            // Falls through to the cart below.
        }

        return $this->redirectFactory->create()->setPath('checkout/cart');
    }
}
