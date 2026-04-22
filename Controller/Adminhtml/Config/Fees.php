<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Controller\Adminhtml\Config;

use Magento\Backend\App\Action;
use Magento\Directory\Model\CurrencyFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Two\Gateway\Service\Api\Adapter;

/**
 * AJAX endpoint for the surcharge grid's "Fee" column.
 *
 * Given a list of term-days + scope, resolves the merchant's API key at that
 * scope and asks the Two API for the merchant fee (percentage + fixed) per
 * term. Returns JSON the admin grid can render read-only.
 *
 * Failure mode: on any upstream error, returns {success:false}. The JS
 * leaves "—" in the fee cells so the admin config page never breaks on a
 * Two API outage.
 */
class Fees extends Action
{
    public const ADMIN_RESOURCE = 'Magento_Sales::config_sales';

    /**
     * @var JsonFactory
     */
    private $resultJsonFactory;

    /**
     * @var Adapter
     */
    private $apiAdapter;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;

    /**
     * @var CurrencyFactory
     */
    private $currencyFactory;

    public function __construct(
        Action\Context $context,
        JsonFactory $resultJsonFactory,
        Adapter $apiAdapter,
        StoreManagerInterface $storeManager,
        ScopeConfigInterface $scopeConfig,
        CurrencyFactory $currencyFactory
    ) {
        parent::__construct($context);
        $this->resultJsonFactory = $resultJsonFactory;
        $this->apiAdapter = $apiAdapter;
        $this->storeManager = $storeManager;
        $this->scopeConfig = $scopeConfig;
        $this->currencyFactory = $currencyFactory;
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

        $response = $this->apiAdapter->execute(
            '/pricing/v1/merchant/rates',
            [
                'buyer_country_code' => $this->resolveBuyerCountry($storeId),
                // TODO: no admin recourse-pricing config exists yet. Matches
                // SurchargeCalculator::fetchBuyerFee hardcode.
                'recourse_pricing' => false,
                // payout_schedule intentionally omitted — server infers from
                // the merchant's payee accounts. Only set if/when we expose
                // an explicit override in admin config.
                'net_terms' => array_values($terms),
            ],
            'POST',
            $storeId
        );

        $normalised = $this->normaliseRatesResponse($response);
        if (!$normalised['success']) {
            return $result->setData($normalised);
        }

        return $result->setData($this->convertFees($normalised, $targetCurrency));
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
        return (string)$this->scopeConfig->getValue('currency/options/base') ?: 'USD';
    }

    /**
     * FX-convert each fee's fixed amount from the API's source currency into
     * the grid's display currency. Percentage is dimensionless.
     *
     * Graceful degrade: on FX failure (typically no rate configured under
     * Stores > Currency Rates) the fees pass through in the source currency.
     * JS shows the currency code inline on the cell so merchants see real
     * numbers instead of "—".
     */
    private function convertFees(array $raw, string $targetCurrency): array
    {
        if (empty($raw['success']) || empty($raw['fees'])) {
            return $raw;
        }
        $sourceCurrency = (string)($raw['currency'] ?? '');
        if ($sourceCurrency === '' || $sourceCurrency === $targetCurrency) {
            $raw['currency'] = $targetCurrency;
            return $raw;
        }

        $rate = $this->resolveFxRate($sourceCurrency, $targetCurrency);
        if ($rate === null) {
            // Leave fees in source currency — JS will label the cell.
            return $raw;
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
     * Look up a direct FX rate; if absent, try the inverse and invert it.
     *
     * Magento's admin only captures rates in one direction (typically from
     * the store's base currency outward), and Currency::convert() does no
     * inversion. Checking both directions avoids forcing the merchant to
     * duplicate every rate row just to display our fee column.
     */
    private function resolveFxRate(string $source, string $target): ?float
    {
        try {
            $direct = (float)$this->currencyFactory->create()->load($source)->getRate($target);
            if ($direct > 0) {
                return $direct;
            }
        } catch (\Exception $e) {
            // fall through to inverse attempt
        }
        try {
            $reverse = (float)$this->currencyFactory->create()->load($target)->getRate($source);
            if ($reverse > 0) {
                return 1 / $reverse;
            }
        } catch (\Exception $e) {
            // fall through to null
        }
        return null;
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
        return $country !== '' ? strtoupper($country) : 'NO';
    }

    /**
     * Flatten the merchant/rates response into the shape the grid JS
     * consumes: {success, currency, fees: {"<days>": {percentage, fixed}}}.
     * Handles the Adapter's failure envelope too.
     */
    private function normaliseRatesResponse(array $response): array
    {
        if (isset($response['error_code']) || !isset($response['rates'])) {
            return ['success' => false, 'error' => 'upstream'];
        }

        $fees = [];
        foreach ((array)$response['rates'] as $rate) {
            if (!isset($rate['net_terms'])) {
                continue;
            }
            $days = (int)$rate['net_terms'];
            $fees[(string)$days] = [
                // API sends strings — cast for JSON numeric output.
                'percentage' => (float)($rate['percentage_fee'] ?? 0),
                'fixed' => (float)($rate['fixed_fee'] ?? 0),
            ];
        }

        return [
            'success' => true,
            'currency' => (string)($response['currency'] ?? ''),
            'fees' => $fees,
        ];
    }
}
