<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Service\Order;

use Magento\Customer\Api\Data\AddressInterfaceFactory as CustomerAddressFactory;
use Magento\Customer\Api\Data\RegionInterfaceFactory as CustomerAddressRegionFactory;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Quote\Api\Data\ShippingAssignmentInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address as QuoteAddress;
use Magento\Tax\Api\Data\QuoteDetailsInterfaceFactory;
use Magento\Tax\Api\Data\QuoteDetailsItemInterfaceFactory;
use Magento\Tax\Api\Data\TaxClassKeyInterface;
use Magento\Tax\Api\Data\TaxClassKeyInterfaceFactory;
use Magento\Tax\Api\TaxCalculationInterface;
use Magento\Tax\Api\TaxClassRepositoryInterface;
use Two\Gateway\Api\Log\RepositoryInterface as LogRepository;

/**
 * Resolves surcharge tax through Magento's real tax rules engine.
 *
 * Mirrors how Magento core taxes its own non-catalog line item —
 * shipping (CommonTaxCollector::getShippingDataObject): build a
 * QuoteDetailsItem carrying the configured Product Tax Class as a
 * TYPE_ID TaxClassKey, wrap it in QuoteDetails with the quote's
 * billing/shipping addresses and customer tax class, and hand it to
 * TaxCalculationInterface::calculateTax(). That gives the surcharge
 * destination-aware rate resolution via Tax Rules (Customer Tax Class
 * x Product Tax Class x Tax Rate), native additive multi-rate
 * stacking (US state+local, CA GST+PST), and zero when no rule
 * matches — the same treatment every real product line gets.
 */
class SurchargeTaxCalculator
{
    /**
     * Item code/type used in the QuoteDetails we submit. Namespaced so
     * it can never collide with core item types ('product', 'shipping').
     */
    public const ITEM_CODE = 'two_surcharge';

    /**
     * Class name of the auto-provisioned always-zero Product Tax Class
     * (created by Setup\Patch\Data\SurchargeNoTaxClass). Ships with no
     * Tax Rule attached, so selecting it guarantees an untaxed
     * surcharge for every destination. calculateForQuote() logs a
     * warning if this class ever resolves non-zero tax — that means a
     * merchant attached a Tax Rule to it and silently broke the
     * guarantee.
     */
    public const NO_TAX_CLASS_NAME = 'Payment Terms Surcharge - No Tax';

    /**
     * @var TaxCalculationInterface
     */
    private $taxCalculation;

    /**
     * @var QuoteDetailsInterfaceFactory
     */
    private $quoteDetailsFactory;

    /**
     * @var QuoteDetailsItemInterfaceFactory
     */
    private $quoteDetailsItemFactory;

    /**
     * @var TaxClassKeyInterfaceFactory
     */
    private $taxClassKeyFactory;

    /**
     * @var CustomerAddressFactory
     */
    private $customerAddressFactory;

    /**
     * @var CustomerAddressRegionFactory
     */
    private $customerAddressRegionFactory;

    /**
     * @var TaxClassRepositoryInterface
     */
    private $taxClassRepository;

    /**
     * @var LogRepository
     */
    private $logRepository;

    public function __construct(
        TaxCalculationInterface $taxCalculation,
        QuoteDetailsInterfaceFactory $quoteDetailsFactory,
        QuoteDetailsItemInterfaceFactory $quoteDetailsItemFactory,
        TaxClassKeyInterfaceFactory $taxClassKeyFactory,
        CustomerAddressFactory $customerAddressFactory,
        CustomerAddressRegionFactory $customerAddressRegionFactory,
        TaxClassRepositoryInterface $taxClassRepository,
        LogRepository $logRepository
    ) {
        $this->taxCalculation = $taxCalculation;
        $this->quoteDetailsFactory = $quoteDetailsFactory;
        $this->quoteDetailsItemFactory = $quoteDetailsItemFactory;
        $this->taxClassKeyFactory = $taxClassKeyFactory;
        $this->customerAddressFactory = $customerAddressFactory;
        $this->customerAddressRegionFactory = $customerAddressRegionFactory;
        $this->taxClassRepository = $taxClassRepository;
        $this->logRepository = $logRepository;
    }

    /**
     * Calculate surcharge tax for the given net amounts via Tax Rules.
     *
     * Runs calculateTax() twice — quote currency and base currency —
     * exactly as core's Tax collector computes taxDetails and
     * baseTaxDetails, so base amounts don't inherit quote-currency
     * rounding artefacts.
     *
     * @param Quote $quote
     * @param ShippingAssignmentInterface $shippingAssignment
     * @param float $netAmount surcharge net, quote currency
     * @param float $baseNetAmount surcharge net, base currency
     * @param int $taxClassId configured Product Tax Class id (0 = None)
     * @param int $storeId
     *
     * @return array{tax_amount: float, base_tax_amount: float, tax_rate: float}
     */
    public function calculateForQuote(
        Quote $quote,
        ShippingAssignmentInterface $shippingAssignment,
        float $netAmount,
        float $baseNetAmount,
        int $taxClassId,
        int $storeId
    ): array {
        $taxDetails = $this->taxCalculation->calculateTax(
            $this->buildQuoteDetails($quote, $shippingAssignment, $netAmount, $taxClassId),
            $storeId,
            false
        );
        $baseTaxDetails = $this->taxCalculation->calculateTax(
            $this->buildQuoteDetails($quote, $shippingAssignment, $baseNetAmount, $taxClassId),
            $storeId,
            false
        );

        $taxAmount = 0.0;
        $taxRate = 0.0;
        foreach ((array)$taxDetails->getItems() as $item) {
            if ($item->getCode() === self::ITEM_CODE) {
                $taxAmount = (float)$item->getRowTax();
                $taxRate = (float)$item->getTaxPercent();
            }
        }
        $baseTaxAmount = 0.0;
        foreach ((array)$baseTaxDetails->getItems() as $item) {
            if ($item->getCode() === self::ITEM_CODE) {
                $baseTaxAmount = (float)$item->getRowTax();
            }
        }

        if ($taxAmount > 0) {
            $this->warnIfNoTaxClassIsTaxed($taxClassId, $taxAmount, $taxRate);
        }

        return [
            'tax_amount' => round($taxAmount, 6),
            'base_tax_amount' => round($baseTaxAmount, 6),
            'tax_rate' => $taxRate,
        ];
    }

    /**
     * Build the QuoteDetails submission, mirroring core's
     * CommonTaxCollector::prepareQuoteDetails() + getShippingDataObject().
     */
    private function buildQuoteDetails(
        Quote $quote,
        ShippingAssignmentInterface $shippingAssignment,
        float $amount,
        int $taxClassId
    ) {
        $item = $this->quoteDetailsItemFactory->create()
            ->setType(self::ITEM_CODE)
            ->setCode(self::ITEM_CODE)
            ->setQuantity(1)
            ->setUnitPrice($amount)
            ->setIsTaxIncluded(false)
            ->setTaxClassKey(
                $this->taxClassKeyFactory->create()
                    ->setType(TaxClassKeyInterface::TYPE_ID)
                    ->setValue($taxClassId)
            );

        $shippingAddress = $shippingAssignment->getShipping()->getAddress();

        $quoteDetails = $this->quoteDetailsFactory->create();
        $quoteDetails->setBillingAddress($this->mapAddress($quote->getBillingAddress()));
        $quoteDetails->setShippingAddress($this->mapAddress($shippingAddress));
        $quoteDetails->setCustomerTaxClassKey(
            $this->taxClassKeyFactory->create()
                ->setType(TaxClassKeyInterface::TYPE_ID)
                ->setValue($quote->getCustomerTaxClassId())
        );
        $quoteDetails->setCustomerId($quote->getCustomerId());
        $quoteDetails->setItems([$item]);

        return $quoteDetails;
    }

    /**
     * Map a quote address onto the customer AddressInterface shape the
     * tax engine consumes — verbatim CommonTaxCollector::mapAddress().
     *
     * @param QuoteAddress|null $address
     * @return \Magento\Customer\Api\Data\AddressInterface|null
     */
    private function mapAddress($address)
    {
        if ($address === null) {
            return null;
        }
        $region = $this->customerAddressRegionFactory->create(
            [
                'data' => [
                    'region_id' => $address->getRegionId(),
                    'region_code' => $address->getRegionCode(),
                    'region' => $address->getRegion(),
                ],
            ]
        );

        return $this->customerAddressFactory->create(
            [
                'data' => [
                    'country_id' => $address->getCountryId(),
                    'region' => $region,
                    'postcode' => $address->getPostcode(),
                    'city' => $address->getCity(),
                    'street' => $address->getStreet(),
                ],
            ]
        );
    }

    /**
     * Defensive guard for the always-zero guarantee: if the configured
     * class is the auto-provisioned no-tax class but the engine
     * resolved real tax, a merchant has attached a Tax Rule to it.
     * Warn loudly (error log) but do NOT fail checkout — the engine
     * result is still internally consistent, just not what the class
     * name promises.
     */
    private function warnIfNoTaxClassIsTaxed(int $taxClassId, float $taxAmount, float $taxRate): void
    {
        if ($taxClassId <= 0) {
            return;
        }
        try {
            $taxClass = $this->taxClassRepository->get($taxClassId);
        } catch (NoSuchEntityException $e) {
            // Configured class deleted after selection: TYPE_ID key still
            // resolved tax via a rule referencing the raw id, or another
            // edge. Surface it — merchant should re-point the config.
            $this->logRepository->addErrorLog(
                'SurchargeTaxCalculator: configured surcharge tax class no longer exists',
                ['tax_class_id' => $taxClassId]
            );
            return;
        }
        if ($taxClass->getClassName() === self::NO_TAX_CLASS_NAME) {
            $this->logRepository->addErrorLog(
                'SurchargeTaxCalculator: the "' . self::NO_TAX_CLASS_NAME . '" tax class has a Tax Rule '
                . 'attached and resolved non-zero surcharge tax. This class must stay rule-free to '
                . 'guarantee an untaxed surcharge — detach the Tax Rule or select a different class.',
                ['tax_class_id' => $taxClassId, 'tax_amount' => $taxAmount, 'tax_rate' => $taxRate]
            );
        }
    }
}
