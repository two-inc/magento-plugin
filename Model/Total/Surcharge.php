<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Model\Total;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Quote\Api\Data\ShippingAssignmentInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address\Total;
use Magento\Quote\Model\Quote\Address\Total\AbstractTotal;
use Two\Gateway\Api\Config\RepositoryInterface as ConfigRepository;
use Two\Gateway\Api\Log\RepositoryInterface as LogRepository;
use Two\Gateway\Model\Config\Source\SurchargeType;
use Two\Gateway\Service\Order\SurchargeCalculator;
use Two\Gateway\Service\Order\SurchargeTaxCalculator;

/**
 * Quote total collector for the Two payment terms surcharge.
 *
 * Runs during Magento's collectTotals() pipeline. Reads the buyer's
 * selected term from the checkout session, calculates the surcharge
 * via SurchargeCalculator, and adds it to the quote grand total.
 *
 * The surcharge details are stored in the session so ComposeOrder
 * can include the line item in Two's API payload without recalculating.
 */
class Surcharge extends AbstractTotal
{
    /**
     * @var CheckoutSession
     */
    private $checkoutSession;

    /**
     * @var ConfigRepository
     */
    private $configRepository;

    /**
     * @var SurchargeCalculator
     */
    private $surchargeCalculator;

    /**
     * @var SurchargeTaxCalculator
     */
    private $surchargeTaxCalculator;

    /**
     * @var LogRepository
     */
    private $logRepository;

    /**
     * @var array<string, true> set of payment-method codes (as keys) that
     *                          engage the surcharge collector. Populated via
     *                          DI; brand overlays append their own code.
     *                          Keyed set so collect() uses O(1) isset() on
     *                          the hot collectTotals path.
     */
    private $allowedMethods;

    public function __construct(
        CheckoutSession $checkoutSession,
        ConfigRepository $configRepository,
        SurchargeCalculator $surchargeCalculator,
        SurchargeTaxCalculator $surchargeTaxCalculator,
        LogRepository $logRepository,
        array $allowedMethods = ['two_payment']
    ) {
        $this->checkoutSession = $checkoutSession;
        $this->configRepository = $configRepository;
        $this->surchargeCalculator = $surchargeCalculator;
        $this->surchargeTaxCalculator = $surchargeTaxCalculator;
        $this->logRepository = $logRepository;
        $this->allowedMethods = array_fill_keys($allowedMethods, true);
        $this->setCode('two_surcharge');
    }

    /**
     * @inheritDoc
     */
    public function collect(
        Quote $quote,
        ShippingAssignmentInterface $shippingAssignment,
        Total $total
    ): self {
        parent::collect($quote, $shippingAssignment, $total);

        // Only run when there are items (avoids double-counting on empty assignments)
        $itemCount = count($shippingAssignment->getItems());
        if (!$itemCount) {
            $this->logRepository->addDebugLog('TotalCollector: skipped (no items)', []);
            return $this;
        }

        // Only engage once Two is the selected payment method. Magento
        // recollects totals on payment-method change, so the surcharge
        // appears the moment the buyer picks Two at checkout. Computing
        // earlier would put our pricing API in the add-to-cart hot path.
        // Quote::getPayment() returns an empty Payment object when none is
        // attached (not null), but guard defensively — total collectors run
        // on every collectTotals() and any edge-case null would fatal the
        // entire checkout flow.
        $payment = $quote->getPayment();
        $paymentMethod = $payment ? $payment->getMethod() : null;
        if (!isset($this->allowedMethods[$paymentMethod])) {
            $this->logRepository->addDebugLog(
                'TotalCollector: skipped (payment method: ' . ($paymentMethod ?: 'none') . ')',
                []
            );
            $this->clearSessionSurcharge();
            $this->clearTotalSurcharge($total, $quote);
            return $this;
        }

        $storeId = (int)$quote->getStoreId();
        $surchargeType = $this->configRepository->getSurchargeType($storeId);

        if ($surchargeType === SurchargeType::NONE) {
            $this->logRepository->addDebugLog('TotalCollector: skipped (type=none)', []);
            $this->clearSessionSurcharge();
            $this->clearTotalSurcharge($total, $quote);
            return $this;
        }

        $selectedDays = $this->getSelectedTermDays($storeId);
        if ($selectedDays <= 0) {
            $this->logRepository->addDebugLog('TotalCollector: skipped (no term selected)', []);
            $this->clearSessionSurcharge();
            $this->clearTotalSurcharge($total, $quote);
            return $this;
        }

        $grandTotal = (float)$total->getGrandTotal();
        $currency = $quote->getQuoteCurrencyCode()
            ?: $quote->getStore()->getBaseCurrencyCode();

        $country = $this->resolveBuyerCountry($quote);

        $this->logRepository->addDebugLog('TotalCollector: calculating', [
            'items' => $itemCount,
            'type' => $surchargeType,
            'days' => $selectedDays,
            'grandTotal' => $grandTotal,
            'currency' => $currency,
            'country' => $country,
        ]);

        try {
            $result = $this->surchargeCalculator->calculate(
                $grandTotal,
                $selectedDays,
                $country,
                $currency,
                $storeId
            );
        } catch (\Magento\Framework\Exception\LocalizedException $e) {
            // Surface to checkout so buyer sees the error (e.g. missing FX rate)
            throw $e;
        } catch (\Exception $e) {
            // Never silently zero the surcharge on unexpected failures (API down,
            // malformed response, etc). Merchant loses revenue if we do; buyer
            // would pay without the surcharge line ever appearing. Surface a
            // user-facing error and let checkout halt until the API recovers.
            $this->logRepository->addErrorLog('TotalCollector: calculation failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            $this->clearSessionSurcharge();
            throw new \Magento\Framework\Exception\LocalizedException(
                __('Unable to calculate payment terms surcharge. Please try again in a moment.'),
                $e
            );
        }

        $netAmount = round((float)$result['amount'], 6);
        if ($netAmount <= 0) {
            $this->logRepository->addDebugLog('TotalCollector: zero surcharge', ['result' => $result]);
            $this->clearSessionSurcharge();
            $this->clearTotalSurcharge($total, $quote);
            return $this;
        }

        // Keep 6dp internally; the API outbound boundary distinguishes
        // Money fields (gross/net/tax/discount, 2dp), UnitPrice (6dp),
        // and Rate (tax_rate, 6dp). 6dp is the upper precision the API
        // accepts for any field, so there's no point being more precise
        // internally than the wire can carry — and being LESS precise
        // (the earlier 2dp truncation, or the interim 4dp) loses
        // sub-cent in tax computations and base-currency conversions
        // that accumulates into grand_total and produces visible 1-cent
        // display drift when the running sum crosses a rounding
        // boundary. See internal ticket for the original 2dp→4dp diagnosis;
        // the 4dp→6dp bump aligns with the formalised API precision
        // contract (Money 2dp / UnitPrice 6dp / Rate 6dp / Quantity 8dp).
        // ComposeOrder / ComposeRefund / ComposeCapture / ComposeShipment
        // do the per-field outbound rounding via roundAmt().
        $baseToQuoteRate = (float)$quote->getBaseToQuoteRate() ?: 1.0;
        $baseNetAmount = round($netAmount / $baseToQuoteRate, 6);

        // Tax: destination-aware via Magento's tax rules engine when a
        // surcharge Product Tax Class is configured (TWO-25072), else the
        // legacy flat admin-configured percentage from the pricing result.
        $surchargeTaxClassId = $this->configRepository->getSurchargeTaxClassId($storeId);
        if ($surchargeTaxClassId !== null) {
            try {
                $taxResult = $this->surchargeTaxCalculator->calculateForQuote(
                    $quote,
                    $shippingAssignment,
                    $netAmount,
                    $baseNetAmount,
                    $surchargeTaxClassId,
                    $storeId
                );
            } catch (\Exception $e) {
                // Same posture as the surcharge calculation above: never
                // silently zero the tax on unexpected failure — surface a
                // user-facing error rather than under-charge the buyer.
                $this->logRepository->addErrorLog('TotalCollector: surcharge tax calculation failed', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
                $this->clearSessionSurcharge();
                throw new \Magento\Framework\Exception\LocalizedException(
                    __('Unable to calculate payment terms surcharge. Please try again in a moment.'),
                    $e
                );
            }
            $taxAmount = round($taxResult['tax_amount'], 6);
            $baseTaxAmount = round($taxResult['base_tax_amount'], 6);
            $taxRatePercent = (float)$taxResult['tax_rate'];
        } else {
            $taxRatePercent = (float)$result['tax_rate'];
            $taxAmount = round($netAmount * $taxRatePercent / 100, 6);
            $baseTaxAmount = round($taxAmount / $baseToQuoteRate, 6);
        }

        $grossAmount = round($netAmount + $taxAmount, 6);

        // Convert to base currency for base_* fields (order totals/tax reports)
        $baseGrossAmount = round($baseNetAmount + $baseTaxAmount, 6);

        $total->setGrandTotal($grandTotal + $grossAmount);
        $total->setBaseGrandTotal((float)$total->getBaseGrandTotal() + $baseGrossAmount);
        $total->setTaxAmount((float)$total->getTaxAmount() + $taxAmount);
        $total->setBaseTaxAmount((float)$total->getBaseTaxAmount() + $baseTaxAmount);

        // Persist on the quote-address total so sales_convert_quote_address
        // fieldset copies the values onto the order at conversion time.
        // We deliberately do NOT mirror onto the quote itself — the quote
        // collector runs on every shipping/address change, and a clobber on
        // a speculative pass (no items, no two_payment, etc.) would zero a
        // valid value set by an earlier pass for the placement address.
        $total->setData('two_surcharge_amount', $netAmount);
        $total->setData('base_two_surcharge_amount', $baseNetAmount);
        $total->setData('two_surcharge_tax_amount', $taxAmount);
        $total->setData('base_two_surcharge_tax_amount', $baseTaxAmount);
        $total->setData('two_surcharge_description', $result['description']);
        $total->setData('two_surcharge_tax_rate', $taxRatePercent);

        // Note: setData/setTitle/setValue on $total here doesn't propagate to
        // segment building. Magento's TotalsReader::fetch() builds fresh Total
        // instances from each collector's fetch() return value via setData().
        // Title/value must therefore be emitted by fetch(), not set here.
        // Session is the only reliable cross-phase channel.

        // Store in session for ComposeOrder and cross-request persistence
        $this->checkoutSession->setTwoSurchargeAmount($netAmount);
        $this->checkoutSession->setTwoSurchargeTax($taxAmount);
        $this->checkoutSession->setTwoSurchargeGross($grossAmount);
        $this->checkoutSession->setTwoSurchargeDescription($result['description']);
        $this->checkoutSession->setTwoSurchargeTaxRate($taxRatePercent);

        $this->logRepository->addDebugLog('TotalCollector: applied', [
            'net' => $netAmount,
            'tax' => $taxAmount,
            'gross' => $grossAmount,
            'newGrandTotal' => $grandTotal + $grossAmount,
        ]);

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function fetch(Quote $quote, Total $total): array
    {
        // Read from session: Magento's TotalsReader::fetch() builds fresh Total
        // instances from each collector's fetch() return value, so anything
        // set on $total in collect() is lost by the time we get here.
        // Returns net amount — surcharge tax is included in the Tax line via setTaxAmount.
        $amount = (float)$this->checkoutSession->getTwoSurchargeAmount();

        $this->logRepository->addDebugLog('TotalCollector fetch()', [
            'amount' => $amount,
        ]);

        if ($amount <= 0) {
            return [];
        }

        $title = $this->checkoutSession->getTwoSurchargeDescription() ?: __('Payment terms fee');

        return [
            'code' => $this->getCode(),
            // TotalsConverter::process() requires title to be a Phrase object
            // (checks is_object() then calls ->render()); plain strings are
            // dropped and the client-side segment gets an empty title.
            'title' => new \Magento\Framework\Phrase((string)$title),
            'value' => $amount,
        ];
    }

    private function getSelectedTermDays(int $storeId): int
    {
        $sessionTerm = (int)$this->checkoutSession->getTwoSelectedTerm();
        if ($sessionTerm > 0) {
            return $sessionTerm;
        }
        return $this->configRepository->getDefaultPaymentTerm($storeId);
    }

    /**
     * Resolve buyer country in precedence order: billing, shipping, store
     * default (`general/country/default`). Returns empty string if none
     * are set — collect() runs after method selection, by which point
     * the buyer has provided an address, so the empty branch is
     * effectively dead in normal checkout. Better to surface "no
     * country" than to invent a region-specific guess.
     */
    private function resolveBuyerCountry(Quote $quote): string
    {
        $billing = $quote->getBillingAddress();
        if ($billing && $billing->getCountryId()) {
            return $billing->getCountryId();
        }
        $shipping = $quote->getShippingAddress();
        if ($shipping && $shipping->getCountryId()) {
            return $shipping->getCountryId();
        }
        return (string) $quote->getStore()->getConfig('general/country/default');
    }

    private function clearSessionSurcharge(): void
    {
        $this->checkoutSession->setTwoSurchargeAmount(0);
        $this->checkoutSession->setTwoSurchargeTax(0);
        $this->checkoutSession->setTwoSurchargeGross(0);
        $this->checkoutSession->setTwoSurchargeDescription('');
        $this->checkoutSession->setTwoSurchargeTaxRate(0);
    }

    /**
     * Reset the quote-address total fields so a stale surcharge from an
     * earlier collect() pass doesn't survive once conditions change.
     * Only operates on the in-memory $total (i.e. the quote address row);
     * does NOT touch the quote itself — see the comment in collect() above.
     */
    private function clearTotalSurcharge(Total $total, Quote $quote): void
    {
        $total->setData('two_surcharge_amount', 0);
        $total->setData('base_two_surcharge_amount', 0);
        $total->setData('two_surcharge_tax_amount', 0);
        $total->setData('base_two_surcharge_tax_amount', 0);
        $total->setData('two_surcharge_description', '');
        $total->setData('two_surcharge_tax_rate', 0);
    }
}
