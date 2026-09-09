<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Service\Order\Doubles;

use Two\Gateway\Service\Order\SurchargeCalculator;

/**
 * Counts quote attempts while still running the real pricing path.
 *
 * A guard that concedes must be provable by the gate never ASKING, not merely
 * by no request reaching the wire: calculate() short-circuits some inputs
 * itself, so an adapter-only count credits the gate with the calculator's work.
 */
class RecordingSurchargeCalculator extends SurchargeCalculator
{
    public int $attempts = 0;

    public function calculate(
        float $grossAmount,
        int $selectedTermDays,
        string $buyerCountry,
        string $orderCurrency,
        ?int $storeId = null,
        ?int $timeoutSeconds = null
    ): array {
        $this->attempts++;
        return parent::calculate(
            $grossAmount,
            $selectedTermDays,
            $buyerCountry,
            $orderCurrency,
            $storeId,
            $timeoutSeconds
        );
    }
}
