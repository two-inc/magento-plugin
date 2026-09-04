<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Model\Config\Comment;

use Magento\Config\Model\Config\CommentInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Store\Model\StoreManagerInterface;
use Two\Gateway\Service\Order\MinimumOrderProvider;
use Two\Gateway\Api\CurrencyRatesProviderInterface;

/**
 * Shows the merchant the platform/partner minimum their own value must
 * meet or exceed (the API-resolved platform minimum), converted into the store base
 * currency the field is interpreted in, with the platform's native
 * value in brackets - e.g. "Platform minimum £215.73 (€250.00)".
 */
class MerchantMinimumOrder implements CommentInterface
{
    /**
     * @var MinimumOrderProvider
     */
    private $minimumOrderProvider;

    /**
     * @var CurrencyRatesProviderInterface
     */
    private $ratesProvider;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var PriceCurrencyInterface
     */
    private $priceCurrency;

    /**
     * @var RequestInterface
     */
    private $request;

    public function __construct(
        MinimumOrderProvider $minimumOrderProvider,
        CurrencyRatesProviderInterface $ratesProvider,
        StoreManagerInterface $storeManager,
        PriceCurrencyInterface $priceCurrency,
        RequestInterface $request
    ) {
        $this->minimumOrderProvider = $minimumOrderProvider;
        $this->ratesProvider = $ratesProvider;
        $this->storeManager = $storeManager;
        $this->priceCurrency = $priceCurrency;
        $this->request = $request;
    }

    /**
     * @inheritDoc
     */
    public function getCommentText($elementValue)
    {
        $store = $this->resolveScopeStore();
        $platformMinimum = $this->minimumOrderProvider->getMinimum(
            $store !== null ? (int)$store->getId() : null
        );
        if ($platformMinimum === null) {
            return (string)__(
                'Hide the payment method below this order value (store base currency, on the tax basis selected below). Leave empty for no minimum.'
            );
        }

        $baseCurrency = $store !== null ? (string)$store->getBaseCurrencyCode() : '';
        $nativeDisplay = $this->priceCurrency->format(
            $platformMinimum['amount'],
            false,
            2,
            $store,
            $platformMinimum['currency']
        );

        $basisWord = $platformMinimum['basis'] === 'gross' ? __('including') : __('excluding');

        if ($baseCurrency === '' || $baseCurrency === $platformMinimum['currency']) {
            return (string)__(
                'Platform minimum %1, %2 tax. A value here is interpreted in the store base currency on the tax basis selected below and must be at least this.',
                $nativeDisplay,
                $basisWord
            );
        }

        $rate = $this->ratesProvider->getRate(
            $platformMinimum['currency'],
            $baseCurrency,
            $store !== null ? (int)$store->getId() : null
        );

        // TWO-25503: with no rate for the pair the platform floor can only be
        // shown in ITS currency, which the field is not interpreted in. Saying
        // "must be at least this" against a figure in another currency reads as
        // a number the merchant can type, so name the gap instead — the save-time
        // validation cannot compare the two either.
        if ($rate === null || $rate <= 0) {
            return (string)__(
                'Platform minimum %1, %2 tax. A value here is interpreted in %3 on the tax basis selected below,'
                . ' and no exchange rate for %4 to %3 is currently available, so the two cannot be compared.'
                . ' Configure the rate under Stores > Currency Rates.',
                $nativeDisplay,
                $basisWord,
                $baseCurrency,
                $platformMinimum['currency']
            );
        }

        $floorDisplay = $this->priceCurrency->format(
            round($platformMinimum['amount'] * $rate, 2),
            false,
            2,
            $store,
            $baseCurrency
        );

        return (string)__(
            'Platform minimum %1, %2 tax. A value here is interpreted in the store base currency on the tax basis selected below and must be at least this.',
            sprintf('%s (%s)', $floorDisplay, $nativeDisplay),
            $basisWord
        );
    }

    /**
     * The store whose base currency the field is interpreted in, derived
     * from the admin config-scope selector (store param, website default
     * store, or the global default store).
     *
     * Counterpart: Backend\MerchantMinimumOrder::resolveScopeStore() resolves
     * the same scope from the config model at save time. Keep their store
     * resolution in lockstep or the displayed floor and the validated floor
     * can disagree.
     *
     * @return \Magento\Store\Api\Data\StoreInterface|null
     */
    private function resolveScopeStore()
    {
        try {
            if ($storeCode = $this->request->getParam('store')) {
                return $this->storeManager->getStore($storeCode);
            }
            if ($websiteCode = $this->request->getParam('website')) {
                $website = $this->storeManager->getWebsite($websiteCode);
                return $this->storeManager->getStore($website->getDefaultGroup()->getDefaultStoreId());
            }
            return $this->storeManager->getStore();
        } catch (\Exception $e) {
            return null;
        }
    }
}
