<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Service\Order;

use Two\Gateway\Api\Config\RepositoryInterface as ConfigRepository;
use Two\Gateway\Api\Log\RepositoryInterface as LogRepository;
use Two\Gateway\Model\Config\Source\SurchargeType;
use Two\Gateway\Service\Api\Adapter;

/**
 * Calculates buyer surcharge based on merchant config and API fee data.
 *
 * Flow:
 * 1. Fetch merchant fee from POST /pricing/v1/portal/order/fee
 * 2. If differential mode, also fetch fee for default term and use delta
 * 3. Apply merchant's surcharge config (percentage, fixed, limit)
 * 4. Round up to next cent
 */
class SurchargeCalculator
{
    /**
     * @var ConfigRepository
     */
    private $configRepository;

    /**
     * @var Adapter
     */
    private $apiAdapter;

    /**
     * @var LogRepository
     */
    private $logRepository;

    public function __construct(
        ConfigRepository $configRepository,
        Adapter $apiAdapter,
        LogRepository $logRepository
    ) {
        $this->configRepository = $configRepository;
        $this->apiAdapter = $apiAdapter;
        $this->logRepository = $logRepository;
    }

    /**
     * Calculate the buyer's surcharge for a given order and selected term.
     *
     * @param float $grossAmount Order gross amount
     * @param int $selectedTermDays The term the buyer selected
     * @param string $buyerCountry ISO Alpha-2 country code
     * @param int|null $storeId
     *
     * @return array{amount: float, tax_rate: float, description: string}
     */
    public function calculate(
        float $grossAmount,
        int $selectedTermDays,
        string $buyerCountry,
        ?int $storeId = null
    ): array {
        $surchargeType = $this->configRepository->getSurchargeType($storeId);

        if ($surchargeType === SurchargeType::NONE) {
            return ['amount' => 0.0, 'tax_rate' => 0.0, 'description' => ''];
        }

        $feeBase = $this->getFeeBase($grossAmount, $selectedTermDays, $buyerCountry, $storeId);
        $config = $this->configRepository->getSurchargeConfig($selectedTermDays, $storeId);

        $surcharge = 0.0;

        $hasPercentage = in_array($surchargeType, [SurchargeType::PERCENTAGE, SurchargeType::FIXED_AND_PERCENTAGE]);
        $hasFixed = in_array($surchargeType, [SurchargeType::FIXED, SurchargeType::FIXED_AND_PERCENTAGE]);

        if ($hasPercentage) {
            $surcharge += $feeBase * ($config['percentage'] / 100);
        }
        if ($hasFixed) {
            $surcharge += $config['fixed'];
        }

        // Apply limit cap
        if ($config['limit'] > 0 && $surcharge > $config['limit']) {
            $surcharge = $config['limit'];
        }

        // Round up to next cent
        $surcharge = ceil($surcharge * 100) / 100;

        $this->logRepository->addDebugLog('Surcharge calculated', [
            'selected_term' => $selectedTermDays,
            'fee_base' => $feeBase,
            'surcharge_type' => $surchargeType,
            'config' => $config,
            'result' => $surcharge,
        ]);

        return [
            'amount' => $surcharge,
            'tax_rate' => $this->configRepository->getSurchargeTaxRate($storeId),
            'description' => $this->configRepository->getSurchargeLineDescription($storeId),
        ];
    }

    /**
     * Get the fee base for surcharge calculation.
     * In differential mode, this is the delta between selected and default term fees.
     */
    private function getFeeBase(
        float $grossAmount,
        int $selectedTermDays,
        string $buyerCountry,
        ?int $storeId
    ): float {
        $selectedFee = $this->fetchMerchantFee($grossAmount, $selectedTermDays, $buyerCountry, $storeId);

        if (!$this->configRepository->isSurchargeDifferential($storeId)) {
            return $selectedFee;
        }

        $defaultDays = $this->configRepository->getDefaultPaymentTerm($storeId);
        if ($selectedTermDays === $defaultDays) {
            return 0.0; // No surcharge for default term in differential mode
        }

        $defaultFee = $this->fetchMerchantFee($grossAmount, $defaultDays, $buyerCountry, $storeId);
        return max(0.0, $selectedFee - $defaultFee);
    }

    /**
     * Call the pricing API to get the merchant's fee for a given term.
     */
    private function fetchMerchantFee(
        float $grossAmount,
        int $durationDays,
        string $buyerCountry,
        ?int $storeId
    ): float {
        $termsType = $this->configRepository->getPaymentTermsType($storeId);
        $orderTerms = [
            'type' => 'NET_TERMS',
            'duration_days' => $durationDays,
        ];
        if ($termsType === 'end_of_month') {
            $orderTerms['duration_days_calculated_from'] = 'END_OF_MONTH';
        }

        $response = $this->apiAdapter->execute('/pricing/v1/portal/order/fee', [
            'buyer_country_code' => $buyerCountry,
            'approved_on_recourse' => false,
            'gross_amount' => $grossAmount,
            'order_terms' => $orderTerms,
        ]);

        return (float)($response['total_fee'] ?? 0);
    }
}
