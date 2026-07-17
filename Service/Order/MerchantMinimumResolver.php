<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Service\Order;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Builds the merchant's own optional minimum-order tuple, in a given base
 * currency, for a given payment-method code.
 *
 * Single source of truth shared by every consumer that must agree on the
 * merchant's minimum-order constraint: Two::isAvailable()'s server gate,
 * Two::getMinimumOrderVisibility()'s client-display projection,
 * Two::assertOrderMeetsMinimum()'s placement backstop, and
 * Total\Surcharge::collect()'s totals-recollect gate (ABN-463). A second ad
 * hoc copy of this construction is exactly how ABN-463 happened: the
 * totals-recollect gate silently diverged from the visibility gate because
 * nothing forced them to agree. Extend this class, not a private copy.
 */
class MerchantMinimumResolver
{
    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;

    public function __construct(ScopeConfigInterface $scopeConfig)
    {
        $this->scopeConfig = $scopeConfig;
    }

    /**
     * @param string $paymentMethod Payment-method code the admin config is scoped under.
     * @param string $baseCurrency Store base currency the merchant amount is denominated in.
     * @param array<string, mixed>|null $platform Platform minimum, for basis fallback only.
     * @param int|null $storeId Scope for the admin config reads.
     * @return array{amount: float, currency: string, basis: string}|null
     */
    public function resolve(
        string $paymentMethod,
        string $baseCurrency,
        ?array $platform,
        ?int $storeId = null
    ): ?array {
        $merchantValue = (float)$this->scopeConfig->getValue(
            "payment/{$paymentMethod}/merchant_minimum_order",
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
        if ($merchantValue <= 0 || $baseCurrency === '') {
            return null;
        }
        $merchantBasis = (string)$this->scopeConfig->getValue(
            "payment/{$paymentMethod}/merchant_minimum_order_basis",
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
        return [
            'amount' => $merchantValue,
            'currency' => $baseCurrency,
            'basis' => in_array($merchantBasis, ['net', 'gross'], true)
                ? $merchantBasis
                : ($platform['basis'] ?? 'gross'),
        ];
    }
}
