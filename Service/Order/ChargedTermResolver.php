<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Service\Order;

use Magento\Checkout\Model\Session as CheckoutSession;
use Two\Gateway\Api\Config\RepositoryInterface as ConfigRepository;

/**
 * The payment term a checkout would be charged for.
 *
 * Single-sourced so the availability gate can never judge a term the totals
 * collector would not price (ABN-546).
 */
class ChargedTermResolver
{
    private CheckoutSession $checkoutSession;

    private ConfigRepository $configRepository;

    public function __construct(
        CheckoutSession $checkoutSession,
        ConfigRepository $configRepository
    ) {
        $this->checkoutSession = $checkoutSession;
        $this->configRepository = $configRepository;
    }

    /** The buyer's selection, else the configured default; 0 when no term is offered. */
    public function resolve(?int $storeId = null): int
    {
        $selected = (int)$this->checkoutSession->getTwoSelectedTerm();
        if ($selected > 0) {
            return $selected;
        }
        return $this->configRepository->getDefaultPaymentTerm($storeId) ?? 0;
    }
}
