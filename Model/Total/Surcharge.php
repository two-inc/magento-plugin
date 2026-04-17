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
     * @var LogRepository
     */
    private $logRepository;

    public function __construct(
        CheckoutSession $checkoutSession,
        ConfigRepository $configRepository,
        SurchargeCalculator $surchargeCalculator,
        LogRepository $logRepository
    ) {
        $this->checkoutSession = $checkoutSession;
        $this->configRepository = $configRepository;
        $this->surchargeCalculator = $surchargeCalculator;
        $this->logRepository = $logRepository;
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

        $paymentMethod = $quote->getPayment()->getMethod();
        if ($paymentMethod && $paymentMethod !== 'two_payment') {
            $this->logRepository->addDebugLog('TotalCollector: skipped (payment method: ' . $paymentMethod . ')', []);
            $this->clearSessionSurcharge();
            return $this;
        }

        $storeId = (int)$quote->getStoreId();
        $surchargeType = $this->configRepository->getSurchargeType($storeId);

        if ($surchargeType === SurchargeType::NONE) {
            $this->logRepository->addDebugLog('TotalCollector: skipped (type=none)', []);
            $this->clearSessionSurcharge();
            return $this;
        }

        $selectedDays = $this->getSelectedTermDays($storeId);
        if ($selectedDays <= 0) {
            $this->logRepository->addDebugLog('TotalCollector: skipped (no term selected)', []);
            $this->clearSessionSurcharge();
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
            $this->logRepository->addDebugLog('TotalCollector: calculation failed', [
                'error' => $e->getMessage(),
            ]);
            $this->clearSessionSurcharge();
            return $this;
        }

        $netAmount = $result['amount'];
        if ($netAmount <= 0) {
            $this->logRepository->addDebugLog('TotalCollector: zero surcharge', ['result' => $result]);
            $this->clearSessionSurcharge();
            return $this;
        }

        $taxRate = $result['tax_rate'] / 100;
        $taxAmount = round($netAmount * $taxRate, 2);
        $grossAmount = $netAmount + $taxAmount;

        // Convert to base currency for base_* fields (order totals/tax reports)
        $baseToQuoteRate = (float)$quote->getBaseToQuoteRate() ?: 1.0;
        $baseGrossAmount = round($grossAmount / $baseToQuoteRate, 4);
        $baseTaxAmount = round($taxAmount / $baseToQuoteRate, 4);

        $total->setGrandTotal($grandTotal + $grossAmount);
        $total->setBaseGrandTotal((float)$total->getBaseGrandTotal() + $baseGrossAmount);
        $total->setTaxAmount((float)$total->getTaxAmount() + $taxAmount);
        $total->setBaseTaxAmount((float)$total->getBaseTaxAmount() + $baseTaxAmount);

        // Store net on total object for fetch() — tax flows via setTaxAmount above
        $total->setData('two_surcharge_net', $netAmount);
        $total->setData('two_surcharge_description', $result['description']);

        // Store in session for ComposeOrder and cross-request persistence
        $this->checkoutSession->setTwoSurchargeAmount($netAmount);
        $this->checkoutSession->setTwoSurchargeTax($taxAmount);
        $this->checkoutSession->setTwoSurchargeGross($grossAmount);
        $this->checkoutSession->setTwoSurchargeDescription($result['description']);
        $this->checkoutSession->setTwoSurchargeTaxRate($result['tax_rate']);

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
        // Prefer the total object (same request as collect), fall back to session.
        // Returns net amount — surcharge tax is included in the Tax line via setTaxAmount.
        $amount = (float)$total->getData('two_surcharge_net')
            ?: (float)$this->checkoutSession->getTwoSurchargeAmount();

        $this->logRepository->addDebugLog('TotalCollector fetch()', [
            'totalNet' => (float)$total->getData('two_surcharge_net'),
            'sessionNet' => (float)$this->checkoutSession->getTwoSurchargeAmount(),
            'amount' => $amount,
        ]);

        if ($amount <= 0) {
            return [];
        }

        $title = $total->getData('two_surcharge_description')
            ?: $this->checkoutSession->getTwoSurchargeDescription()
            ?: 'Payment terms fee';

        return [
            'code' => $this->getCode(),
            'title' => $title,
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
        return $quote->getStore()->getConfig('general/country/default') ?: 'NO';
    }

    private function clearSessionSurcharge(): void
    {
        $this->checkoutSession->setTwoSurchargeAmount(0);
        $this->checkoutSession->setTwoSurchargeTax(0);
        $this->checkoutSession->setTwoSurchargeGross(0);
        $this->checkoutSession->setTwoSurchargeDescription('');
        $this->checkoutSession->setTwoSurchargeTaxRate(0);
    }
}
