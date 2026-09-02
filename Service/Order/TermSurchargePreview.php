<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Service\Order;

use Magento\Quote\Model\Quote;
use Two\Gateway\Api\Config\RepositoryInterface as ConfigRepository;
use Two\Gateway\Api\Log\RepositoryInterface as LogRepository;

/**
 * Per-term surcharge previews for the checkout term chips.
 *
 * Shared by both anonymous surcharge endpoints (Model\Webapi\Surcharges and
 * Model\Webapi\TermSelection) so a chip shows the same amount whichever one
 * last answered.
 *
 * Every entry carries net AND gross; the chip renders whichever
 * `tax/cart_display/price` calls for. The tax rate is resolved once per
 * request — it is a property of the tax class and destination, not of the
 * term — and applied to each term's net.
 */
class TermSurchargePreview
{
    private ConfigRepository $configRepository;

    private SurchargeCalculator $surchargeCalculator;

    private SurchargeTaxCalculator $surchargeTaxCalculator;

    private SurchargeDisplay $surchargeDisplay;

    private LogRepository $logRepository;

    public function __construct(
        ConfigRepository $configRepository,
        SurchargeCalculator $surchargeCalculator,
        SurchargeTaxCalculator $surchargeTaxCalculator,
        SurchargeDisplay $surchargeDisplay,
        LogRepository $logRepository
    ) {
        $this->configRepository = $configRepository;
        $this->surchargeCalculator = $surchargeCalculator;
        $this->surchargeTaxCalculator = $surchargeTaxCalculator;
        $this->surchargeDisplay = $surchargeDisplay;
        $this->logRepository = $logRepository;
    }

    public function taxDisplay(Quote $quote): string
    {
        return $this->surchargeDisplay->forCart($quote->getStore());
    }

    /**
     * @param list<int|string> $terms
     * @return list<array{days: int, net: float, gross: float}>
     */
    public function zeroed(array $terms): array
    {
        $surcharges = [];
        foreach ($terms as $days) {
            $surcharges[] = ['days' => (int)$days, 'net' => 0.0, 'gross' => 0.0];
        }
        return $surcharges;
    }

    /**
     * @param list<int|string> $terms
     * @param string $context endpoint name, for the per-term failure log
     * @return list<array{days: int, net: float, gross: float}>
     */
    public function build(
        Quote $quote,
        float $basis,
        array $terms,
        string $country,
        string $currency,
        int $storeId,
        string $context
    ): array {
        $taxRate = $this->resolveTaxRate($quote, $storeId, $context);

        $surcharges = [];
        foreach ($terms as $days) {
            try {
                $result = $this->surchargeCalculator->calculate(
                    $basis,
                    (int)$days,
                    $country,
                    $currency,
                    $storeId
                );
                $net = (float)$result['amount'];
                // Null tax rate means no surcharge tax class is configured, so
                // the flat admin percentage on the pricing result governs.
                $rate = $taxRate ?? (float)$result['tax_rate'];
                $surcharges[] = [
                    'days' => (int)$days,
                    'net' => $net,
                    'gross' => round($net * (1 + $rate / 100), 2),
                ];
            } catch (\Exception $e) {
                // Per-term failure: keep the other terms responsive, but
                // log loudly so the silent zero doesn't mask a broken
                // pricing path that will later detonate at checkout when
                // the buyer actually picks this term.
                $this->logRepository->addErrorLog(
                    sprintf('%s: term %d failed', $context, (int)$days),
                    $e->getMessage()
                );
                $surcharges[] = ['days' => (int)$days, 'net' => 0.0, 'gross' => 0.0];
            }
        }

        return $surcharges;
    }

    /**
     * Null when no surcharge tax class is configured, or when the tax engine
     * refuses — a chip preview must stay responsive, so a failed rate lookup
     * falls back to the pricing result's own rate rather than emptying the
     * chips. The authoritative figure still comes from the total collector,
     * which does surface an engine failure.
     */
    private function resolveTaxRate(Quote $quote, int $storeId, string $context): ?float
    {
        $taxClassId = $this->configRepository->getSurchargeTaxClassId($storeId);
        if ($taxClassId === null) {
            return null;
        }

        try {
            return $this->surchargeTaxCalculator->resolveRateForQuote($quote, $taxClassId, $storeId);
        } catch (\Exception $e) {
            $this->logRepository->addErrorLog(
                sprintf('%s: surcharge tax rate lookup failed', $context),
                $e->getMessage()
            );
            return null;
        }
    }
}
