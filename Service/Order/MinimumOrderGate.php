<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Service\Order;

use Magento\Quote\Api\Data\CartInterface;
use Magento\Quote\Model\Quote;
use Two\Gateway\Api\BrandRegistryInterface;
use Two\Gateway\Api\CurrencyRatesProviderInterface;
use Two\Gateway\Api\Log\RepositoryInterface as LogRepository;

/**
 * Enforces a brand's minimum order value (e.g. the ABN AMRO product
 * requires a €250 minimum) when deciding whether the payment method
 * is offered at checkout.
 *
 * The minimum is declared per brand in brand.xml as
 * `<minimum_order amount="250" currency="EUR"/>`. Baskets in a
 * different currency are converted to the brand currency via the
 * store's exchange rates before comparing. When no rate is
 * configured the gate fails closed: the method is hidden rather
 * than offered on an order we cannot prove satisfies the brand's
 * product minimum.
 */
class MinimumOrderGate
{
    /**
     * @var CurrencyRatesProviderInterface
     */
    private $ratesProvider;

    /**
     * @var LogRepository
     */
    private $logRepository;

    public function __construct(
        CurrencyRatesProviderInterface $ratesProvider,
        LogRepository $logRepository
    ) {
        $this->ratesProvider = $ratesProvider;
        $this->logRepository = $logRepository;
    }

    /**
     * Whether the quote satisfies the brand's minimum order value.
     *
     * The brand is passed by the payment-method instance rather than
     * resolved from DI so that side-by-side method instances (vanilla
     * `two_payment` next to an overlay's method) each gate on their
     * own brand binding.
     */
    public function isSatisfied(BrandRegistryInterface $brand, ?CartInterface $quote): bool
    {
        $minimumOrder = $brand->getMinimumOrder();
        if ($minimumOrder === null || !$quote instanceof Quote) {
            return true;
        }

        $grandTotal = (float)$quote->getGrandTotal();
        $quoteCurrency = (string)($quote->getQuoteCurrencyCode()
            ?: $quote->getStore()->getBaseCurrencyCode());

        if ($quoteCurrency === $minimumOrder['currency']) {
            return $grandTotal >= $minimumOrder['amount'];
        }

        $rate = $this->ratesProvider->getRate(
            $quoteCurrency,
            $minimumOrder['currency'],
            $quote->getStoreId() !== null ? (int)$quote->getStoreId() : null
        );
        if ($rate === null) {
            $this->logRepository->addDebugLog(
                'MinimumOrderGate: no exchange rate, hiding payment method',
                [
                    'from' => $quoteCurrency,
                    'to' => $minimumOrder['currency'],
                ]
            );
            return false;
        }

        return $grandTotal * $rate >= $minimumOrder['amount'];
    }
}
