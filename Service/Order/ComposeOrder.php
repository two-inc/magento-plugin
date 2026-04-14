<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Service\Order;

use Magento\Catalog\Helper\Image;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollection;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Url;
use Magento\Sales\Api\OrderItemRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Store\Model\App\Emulation;
use Two\Gateway\Api\Config\RepositoryInterface as ConfigRepository;
use Two\Gateway\Model\Config\Source\SurchargeType;
use Two\Gateway\Service\Order as OrderService;
use Two\Gateway\Service\Order\SurchargeCalculator;

/**
 * Compose Order Service
 */
class ComposeOrder extends OrderService
{
    /**
     * @var SurchargeCalculator
     */
    private $surchargeCalculator;

    public function __construct(
        Image $imageHelper,
        ConfigRepository $configRepository,
        CategoryCollection $categoryCollectionFactory,
        OrderItemRepositoryInterface $orderItemRepository,
        Emulation $appEmulation,
        Url $url,
        SurchargeCalculator $surchargeCalculator
    ) {
        parent::__construct($imageHelper, $configRepository, $categoryCollectionFactory, $orderItemRepository, $appEmulation, $url);
        $this->surchargeCalculator = $surchargeCalculator;
    }

    /**
     * Compose request body for two create order
     *
     * @param Order $order
     * @param string $orderReference
     * @param array $additionalData
     * @return array
     * @throws LocalizedException
     */
    public function execute(Order $order, string $orderReference, array $additionalData): array
    {
        $storeId = (int)$order->getStoreId();
        $selectedTermDays = $this->getSelectedTermDays($additionalData, $storeId);

        // Fetch line items from the order
        $lineItems = $this->getLineItemsOrder($order);

        // Calculate and append surcharge line item if applicable
        $surchargeAmount = 0.0;
        $surchargeTax = 0.0;
        if ($this->configRepository->getSurchargeType($storeId) !== SurchargeType::NONE) {
            $buyerCountry = $order->getBillingAddress()
                ? $order->getBillingAddress()->getCountryId()
                : 'NO';

            $surcharge = $this->surchargeCalculator->calculate(
                (float)$order->getGrandTotal(),
                $selectedTermDays,
                $buyerCountry,
                $storeId
            );

            if ($surcharge['amount'] > 0) {
                $surchargeAmount = $surcharge['amount'];
                $taxRate = $surcharge['tax_rate'] / 100;
                $surchargeTax = round($surchargeAmount * $taxRate, 2);

                $lineItems[] = [
                    'order_item_id' => 'surcharge',
                    'name' => $surcharge['description'],
                    'description' => $surcharge['description'],
                    'type' => 'SURCHARGE',
                    'image_url' => '',
                    'product_page_url' => '',
                    'gross_amount' => $this->roundAmt($surchargeAmount + $surchargeTax),
                    'net_amount' => $this->roundAmt($surchargeAmount),
                    'tax_amount' => $this->roundAmt($surchargeTax),
                    'discount_amount' => '0.00',
                    'tax_rate' => $this->roundAmt($taxRate),
                    'tax_class_name' => 'VAT ' . $this->roundAmt($surcharge['tax_rate']) . '%',
                    'unit_price' => $this->roundAmt($surchargeAmount, 6),
                    'quantity' => 1,
                    'quantity_unit' => 'sc',
                ];
            }
        }

        $grossTotal = (float)$order->getGrandTotal() + $surchargeAmount + $surchargeTax;
        $taxTotal = (float)$order->getTaxAmount() + $surchargeTax;
        $netTotal = $grossTotal - $taxTotal;

        // Compose the final payload for the API call
        $payload = [
            'billing_address' => $this->getAddress($order, $additionalData, 'billing'),
            'shipping_address' => $this->getAddress($order, $additionalData, 'shipping'),
            'buyer' => $this->getBuyer($order, $additionalData),
            'buyer_department' => $additionalData['department'] ?? '',
            'buyer_project' => $additionalData['project'] ?? '',
            'buyer_purchase_order_number' => $additionalData['poNumber'] ?? '',
            'currency' => $order->getOrderCurrencyCode(),
            'discount_amount' => $this->roundAmt($this->getDiscountAmountItem($order)),
            'gross_amount' => $this->roundAmt($grossTotal),
            'net_amount' => $this->roundAmt($netTotal),
            'tax_amount' => $this->roundAmt($taxTotal),
            'tax_subtotals' => $this->getTaxSubtotals($lineItems),
            'terms' => $this->getSelectedPaymentTerms($selectedTermDays, $storeId),
            'available_terms' => $this->getAvailableBuyerTerms($storeId),
            'invoice_type' => 'FUNDED_INVOICE',
            'line_items' => $lineItems,
            'merchant_order_id' => (string)($order->getIncrementId()),
            'merchant_urls' => [
                'merchant_confirmation_url' => $this->url->getUrl(
                    'two/payment/confirm',
                    ['_two_order_reference' => base64_encode($orderReference)]
                ),
                'merchant_cancel_order_url' => $this->url->getUrl(
                    'two/payment/cancel',
                    ['_two_order_reference' => base64_encode($orderReference)]
                ),
                'merchant_edit_order_url' => '',
                'merchant_order_verification_failed_url' => $this->url->getUrl(
                    'two/payment/verificationfailed',
                    ['_two_order_reference' => base64_encode($orderReference)]
                ),
            ],
            'order_note' => $additionalData['orderNote'] ?? ''
        ];

        // Add invoice_details and required placeholders only if invoiceEmails are present
        if (!empty($additionalData['invoiceEmails'])) {
            $invoiceDetails = [
                'invoice_emails' => explode(',', $additionalData['invoiceEmails']),
                'payment_reference_message' => '',
                'payment_reference_ocr' => ''
            ];
            $payload['invoice_details'] = $invoiceDetails;
        }

        return $payload;
    }


    /**
     * Get the buyer's selected term from checkout, validated against configured terms.
     */
    private function getSelectedTermDays(array $additionalData, ?int $storeId = null): int
    {
        $selected = (int)($additionalData['selectedTerm'] ?? 0);
        $allowedTerms = $this->configRepository->getAllBuyerTerms($storeId);

        if ($selected > 0 && in_array($selected, $allowedTerms, true)) {
            return $selected;
        }
        return $this->configRepository->getDefaultPaymentTerm($storeId);
    }

    /**
     * Build a terms object for a given duration.
     */
    private function buildTermObject(int $durationDays, ?int $storeId = null): array
    {
        $terms = [
            'type' => 'NET_TERMS',
            'duration_days' => $durationDays,
        ];

        if ($this->configRepository->getPaymentTermsType($storeId) === 'end_of_month') {
            $terms['duration_days_calculated_from'] = 'END_OF_MONTH';
        }

        return $terms;
    }

    /**
     * Build the terms object for the buyer's selected term.
     */
    private function getSelectedPaymentTerms(int $durationDays, ?int $storeId = null): array
    {
        return $this->buildTermObject($durationDays, $storeId);
    }

    /**
     * Get all available buyer terms for the checkout term selector.
     */
    private function getAvailableBuyerTerms(?int $storeId = null): array
    {
        $allTerms = $this->configRepository->getAllBuyerTerms($storeId);

        $available = [];
        foreach ($allTerms as $days) {
            $available[] = $this->buildTermObject($days, $storeId);
        }

        return $available;
    }
}
