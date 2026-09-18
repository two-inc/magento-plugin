<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Observer;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Two\Gateway\Controller\Express\Confirm;

/**
 * Records that THIS add-to-cart actually reached the basket (TWO-25800).
 *
 * `checkout_cart_add_product_complete` is dispatched by
 * `Checkout\Controller\Cart\Add` only after `$this->cart->save()` has returned,
 * so its firing is the one unambiguous statement that the add happened. Nothing
 * else on the request carries that: the response body has no verdict, and the
 * quote's totals answer "is the basket bigger than it was", which a second tab,
 * a stale client-side count or a concurrent request can each make true without
 * this attempt having done anything.
 *
 * So the attempt stamps the session with its own token, and
 * Controller\Express\Confirm requires that token back. The event says an add
 * really happened; the token says it was the add being asked about.
 */
class ExpressAddSucceeded implements ObserverInterface
{
    public function __construct(private readonly CheckoutSession $checkoutSession)
    {
    }

    /**
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer)
    {
        $request = $observer->getEvent()->getData('request');

        if ($request === null || !is_object($request) || !method_exists($request, 'getParam')) {
            return;
        }

        // Only an add this feature started. An ordinary Add to Cart on the same
        // page carries no return_url of ours, and must leave no stamp behind
        // for a later express attempt to read as its own.
        $returnUrl = (string)$request->getParam('return_url');

        if ($returnUrl === '' || strpos($returnUrl, Confirm::ROUTE) === false) {
            return;
        }

        // The attempt's own token, so the confirmation can tell THIS add from
        // any other that happened to complete in the same session.
        $query = (string)parse_url($returnUrl, PHP_URL_QUERY);
        parse_str($query, $params);
        $token = isset($params[Confirm::TOKEN_PARAM]) ? (string)$params[Confirm::TOKEN_PARAM] : '';

        if ($token === '') {
            return;
        }

        // Added to the set rather than replacing it: a second tab completing
        // its own express add must not erase the first tab's completion, which
        // has not been confirmed yet.
        $stamps = $this->checkoutSession->getData(Confirm::SESSION_KEY);
        $stamps = is_array($stamps) ? $stamps : [];
        $stamps[$token] = true;

        if (count($stamps) > Confirm::MAX_STAMPS) {
            $stamps = array_slice($stamps, -Confirm::MAX_STAMPS, null, true);
        }

        $this->checkoutSession->setData(Confirm::SESSION_KEY, $stamps);
    }
}
