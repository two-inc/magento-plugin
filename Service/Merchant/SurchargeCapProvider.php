<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Service\Merchant;

use Two\Gateway\Api\CurrencyRatesProviderInterface;
use Two\Gateway\Model\Config\AdminScope;

/**
 * The merchant's fixed-surcharge cap, expressed in a currency the caller is
 * comparing against.
 *
 * Shared by the admin backend model and the runtime pricing path so the two
 * cannot drift: a fixed amount the admin form accepted must never be clamped
 * at runtime, which only holds while both derive the ceiling from the same
 * arithmetic.
 */
class SurchargeCapProvider
{
    /**
     * @var SettingsProvider
     */
    private $settingsProvider;

    /**
     * @var CurrencyRatesProviderInterface
     */
    private $ratesProvider;

    public function __construct(
        SettingsProvider $settingsProvider,
        CurrencyRatesProviderInterface $ratesProvider
    ) {
        $this->settingsProvider = $settingsProvider;
        $this->ratesProvider = $ratesProvider;
    }

    /**
     * The cap in $targetCurrency, or null when the merchant has no cap.
     *
     * `exact` is false when a cap exists but no rate converts it into
     * $targetCurrency, `amount` then being the unconverted figure. The admin
     * form compares against it anyway, which can only refuse MORE than the real
     * cap would; a path that charges a buyer refuses to price instead, because
     * an unconverted ceiling in a weaker currency admits a fee many times the
     * real cap.
     *
     * The cap is truncated to whole units and a converted figure rounded up, so
     * neither step refuses a fee the merchant is entitled to charge.
     *
     * @return array{amount: int, exact: bool}|null
     */
    public function inCurrency(string $targetCurrency, ?int $storeId, ?string $scope = null): ?array
    {
        $limit = $this->settingsProvider->getSurchargeLimit($storeId, $scope);
        if ($limit === null) {
            return null;
        }

        $amount = (int)$limit['amount'];
        $currency = $limit['currency'];
        if ($targetCurrency === '' || $targetCurrency === $currency) {
            return ['amount' => $amount, 'exact' => $targetCurrency === $currency];
        }

        $rate = $this->ratesProvider->getRate(
            $currency,
            $targetCurrency,
            AdminScope::isStoreScope($scope) ? $storeId : null
        );
        if ($rate !== null && $rate > 0) {
            return ['amount' => (int)ceil($amount * $rate), 'exact' => true];
        }

        return ['amount' => $amount, 'exact' => false];
    }
}
