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
use Two\Gateway\Api\BrandRegistryInterface;
use Two\Gateway\Api\CurrencyRatesProviderInterface;

/**
 * Shows the merchant the platform/partner minimum their own value must
 * exceed (the brand's minimum_order), converted into the store base
 * currency the field is interpreted in, with the platform's native
 * value in brackets - e.g. "Platform minimum £215.73 (€250.00)".
 */
class MerchantMinimumOrder implements CommentInterface
{
    /**
     * @var BrandRegistryInterface
     */
    private $brandRegistry;

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
        BrandRegistryInterface $brandRegistry,
        CurrencyRatesProviderInterface $ratesProvider,
        StoreManagerInterface $storeManager,
        PriceCurrencyInterface $priceCurrency,
        RequestInterface $request
    ) {
        $this->brandRegistry = $brandRegistry;
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
        $platformMinimum = $this->brandRegistry->getMinimumOrder();
        if ($platformMinimum === null) {
            return (string)__(
                'Hide the payment method below this order value (store base currency, including tax). Leave empty for no minimum.'
            );
        }

        $store = $this->resolveScopeStore();
        $baseCurrency = $store !== null ? (string)$store->getBaseCurrencyCode() : '';
        $nativeDisplay = $this->priceCurrency->format(
            $platformMinimum['amount'],
            false,
            2,
            $store,
            $platformMinimum['currency']
        );

        $minimumDisplay = $nativeDisplay;
        if ($baseCurrency !== '' && $baseCurrency !== $platformMinimum['currency']) {
            $rate = $this->ratesProvider->getRate(
                $platformMinimum['currency'],
                $baseCurrency,
                $store !== null ? (int)$store->getId() : null
            );
            if ($rate !== null && $rate > 0) {
                $floorDisplay = $this->priceCurrency->format(
                    round($platformMinimum['amount'] * $rate, 2),
                    false,
                    2,
                    $store,
                    $baseCurrency
                );
                $minimumDisplay = sprintf('%s (%s)', $floorDisplay, $nativeDisplay);
            }
        }

        return (string)__(
            'Platform minimum %1, %2 tax. A value here is interpreted in the store base currency on the same tax basis and must exceed it.',
            $minimumDisplay,
            $platformMinimum['basis'] === 'gross' ? __('including') : __('excluding')
        );
    }

    /**
     * The store whose base currency the field is interpreted in, derived
     * from the admin config-scope selector (store param, website default
     * store, or the global default store).
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
