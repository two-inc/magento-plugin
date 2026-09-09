<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Controller\Adminhtml\Config;

use Magento\Backend\App\Action;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Two\Gateway\Api\CurrencyRatesProviderInterface;
use Two\Gateway\Service\Merchant\FeeRatesProvider;

/**
 * AJAX endpoint for the surcharge grid's "Fee" column.
 *
 * Given a list of term-days + scope, resolves the merchant's API key at that
 * scope and asks the Two API for the merchant fee (percentage + fixed) per
 * term. Returns JSON the admin grid can render read-only.
 *
 * A failed fetch falls back to the last fee set retrieved for this identity
 * and says so through `stale` + `fetched_at`, so the screen can tell the
 * merchant the figures are not current. With nothing cached at all the
 * response is {success:false, error:'upstream'} and the screen says that
 * instead of leaving the fee area blank (ABN-512).
 */
class Fees extends Action
{
    public const ADMIN_RESOURCE = 'Magento_Sales::config_sales';

    /**
     * @var JsonFactory
     */
    private $resultJsonFactory;

    /**
     * @var FeeRatesProvider
     */
    private $feeRates;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;

    /**
     * @var CurrencyRatesProviderInterface
     */
    private $currencyRates;

    /**
     * @var TimezoneInterface
     */
    private $localeDate;

    public function __construct(
        Action\Context $context,
        JsonFactory $resultJsonFactory,
        FeeRatesProvider $feeRates,
        StoreManagerInterface $storeManager,
        ScopeConfigInterface $scopeConfig,
        CurrencyRatesProviderInterface $currencyRates,
        TimezoneInterface $localeDate
    ) {
        parent::__construct($context);
        $this->resultJsonFactory = $resultJsonFactory;
        $this->feeRates = $feeRates;
        $this->storeManager = $storeManager;
        $this->scopeConfig = $scopeConfig;
        $this->currencyRates = $currencyRates;
        $this->localeDate = $localeDate;
    }

    /**
     * @return ResponseInterface|Json|ResultInterface
     */
    public function execute()
    {
        $result = $this->resultJsonFactory->create();

        $terms = $this->getTerms();
        if (empty($terms)) {
            return $result->setData(['success' => false, 'error' => 'no terms']);
        }

        $storeId = $this->resolveStoreId();
        $targetCurrency = $this->resolveTargetCurrency();

        $rates = $this->feeRates->getRates($terms, $this->resolveBuyerCountry($storeId), $storeId);
        if (!$rates['success']) {
            return $result->setData($rates);
        }
        if (!empty($rates['stale']) && isset($rates['fetched_at'])) {
            // Formatted here, in the admin's own locale and timezone, rather
            // than in the browser's.
            $rates['fetched_at_display'] = $this->localeDate->formatDateTime(
                (new \DateTime())->setTimestamp((int)$rates['fetched_at'])
            );
        }

        return $result->setData($this->convertFees($rates, $targetCurrency, $storeId));
    }

    /**
     * Parse and sanitise the requested term-days list from the POST body.
     *
     * @return int[]
     */
    private function getTerms(): array
    {
        $raw = $this->getRequest()->getParam('terms', []);
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : [];
        }
        $terms = [];
        foreach ((array)$raw as $t) {
            $days = (int)$t;
            if ($days > 0) {
                $terms[] = $days;
            }
        }
        return array_values(array_unique($terms));
    }

    /**
     * Map scope + scopeId POSTed by the grid JS to a concrete store ID, so
     * the API call uses the same merchant credentials as the scope the user
     * is configuring.
     */
    private function resolveStoreId(): ?int
    {
        $scope = (string)$this->getRequest()->getParam('scope', 'default');
        $scopeId = (int)$this->getRequest()->getParam('scopeId', 0);

        if ($scope === ScopeInterface::SCOPE_STORES || $scope === 'stores') {
            return $scopeId > 0 ? $scopeId : null;
        }
        if ($scope === ScopeInterface::SCOPE_WEBSITES || $scope === 'websites') {
            if ($scopeId > 0) {
                try {
                    $website = $this->storeManager->getWebsite($scopeId);
                    $store = $website->getDefaultStore();
                    return $store ? (int)$store->getId() : null;
                } catch (\Exception $e) {
                    return null;
                }
            }
        }
        return null;
    }

    /**
     * Resolve the currency the grid is rendered in, matching
     * SurchargeGrid block's getBaseCurrencyCode() exactly so header + fixed
     * amounts line up with the editable columns.
     */
    private function resolveTargetCurrency(): string
    {
        $scope = (string)$this->getRequest()->getParam('scope', 'default');
        $scopeId = (int)$this->getRequest()->getParam('scopeId', 0);

        if ($scope !== 'default' && $scopeId > 0) {
            try {
                if ($scope === ScopeInterface::SCOPE_STORES || $scope === 'stores') {
                    return $this->storeManager->getStore($scopeId)->getBaseCurrencyCode();
                }
                if ($scope === ScopeInterface::SCOPE_WEBSITES || $scope === 'websites') {
                    return $this->storeManager->getWebsite($scopeId)->getBaseCurrencyCode();
                }
            } catch (\Exception $e) {
                // fall through
            }
        }
        return (string)$this->scopeConfig->getValue('currency/options/base') ?: 'EUR';
    }

    /**
     * FX-convert each fee's fixed amount from the API's source currency
     * into the grid's display currency. Percentage is dimensionless.
     *
     * FX failure (no rate in either direction under Stores > Currency Rates)
     * falls through: fees stay in the source currency and the JS renders them
     * with the code inline (e.g. "2.51% + 0.10 GBP"). Merchant fees are
     * billed in the merchant's payout currency regardless, so source-currency
     * display is semantically honest and unblocks admins without FX
     * configured.
     */
    private function convertFees(array $raw, string $targetCurrency, ?int $storeId): array
    {
        if (empty($raw['success']) || empty($raw['fees'])) {
            return $raw;
        }
        $sourceCurrency = (string)($raw['currency'] ?? '');
        if ($sourceCurrency === '' || $sourceCurrency === $targetCurrency) {
            $raw['currency'] = $targetCurrency;
            return $raw;
        }

        $rate = $this->currencyRates->getRate($sourceCurrency, $targetCurrency, $storeId);
        if ($rate === null) {
            return $raw; // leave fees in source currency
        }

        foreach ($raw['fees'] as $days => $fee) {
            if (isset($fee['fixed'])) {
                $raw['fees'][$days]['fixed'] = (float)$fee['fixed'] * $rate;
            }
        }
        $raw['currency'] = $targetCurrency;
        return $raw;
    }

    /**
     * Buyer country for the rate preview. No admin-side config exists for
     * this — use the Magento store's base country as a stand-in. Merchant
     * can override later (e.g. a dropdown) if the proxy turns out wrong.
     */
    private function resolveBuyerCountry(?int $storeId): string
    {
        $scope = $storeId !== null ? ScopeInterface::SCOPE_STORES : 'default';
        $country = (string)$this->scopeConfig->getValue('general/country/default', $scope, $storeId);
        return $country !== '' ? strtoupper($country) : 'NL';
    }
}
