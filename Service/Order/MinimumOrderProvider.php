<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Service\Order;

use Two\Gateway\Service\Merchant\RecordProvider;

/**
 * Resolves the merchant's minimum order value from the Two API.
 *
 * GET /v1/merchant/{id} carries the effective minimum (funding-partner
 * default with any merchant override, resolved server-side) as
 * min_order_amount / min_order_currency / min_order_basis. That is the
 * single source of truth: the same value checkout-api enforces at
 * order create/intent, so the storefront gate and the server can never
 * disagree on the threshold.
 *
 * The merchant record is fetched and cached once by RecordProvider;
 * this class only projects the min_order_* tuple out of it. A record
 * that cannot be resolved (or one without a minimum) yields null: the
 * server still enforces, and hiding the payment method on an API blip
 * would be the worse failure.
 */
class MinimumOrderProvider
{
    /**
     * @var RecordProvider
     */
    private $recordProvider;

    public function __construct(RecordProvider $recordProvider)
    {
        $this->recordProvider = $recordProvider;
    }

    /**
     * The merchant's effective minimum order value, or null when none is
     * configured (or it cannot currently be resolved).
     *
     * @return array{amount: float, currency: string, basis: string}|null
     */
    public function getMinimum(?int $storeId = null): ?array
    {
        $record = $this->recordProvider->getRecord($storeId);
        if ($record === null) {
            return null;
        }
        return $this->parseMinimum($record);
    }

    /**
     * @param array<string,mixed> $merchant
     * @return array{amount: float, currency: string, basis: string}|null
     */
    private function parseMinimum(array $merchant): ?array
    {
        $amount = $merchant['min_order_amount'] ?? null;
        $currency = $merchant['min_order_currency'] ?? null;
        $basis = $merchant['min_order_basis'] ?? null;
        // The API omits all three fields when no minimum is configured; a
        // partial or malformed tuple is treated the same way rather than
        // gating on a guess.
        if (!is_numeric($amount)
            || (float)$amount <= 0
            || !is_string($currency)
            || $currency === ''
            || !in_array($basis, ['net', 'gross'], true)
        ) {
            return null;
        }

        return [
            'amount' => (float)$amount,
            'currency' => strtoupper($currency),
            'basis' => $basis,
        ];
    }
}
