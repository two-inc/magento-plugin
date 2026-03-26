<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Service\Order;

use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Model\Order;
use Two\Gateway\Service\Order as OrderService;

/**
 * Compose Order Service
 */
class ComposeOrder extends OrderService
{

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
        // Fetch line items from the order
        $lineItems = $this->getLineItemsOrder($order);


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
            'gross_amount' => $this->roundAmt($order->getGrandTotal()),
            'net_amount' => $this->roundAmt($order->getGrandTotal() - $order->getTaxAmount()),
            'tax_amount' => $this->roundAmt($order->getTaxAmount()),
            'tax_subtotals' => $this->getTaxSubtotals($lineItems),
            'terms' => $this->getPaymentTerms($order->getStoreId()),
            'available_terms' => $this->getAvailableBuyerTerms($order->getStoreId()),
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
     * Get payment terms for Two API.
     *
     * Returns the default term for the order. The full set of available terms
     * is communicated separately so the buyer can choose during checkout.
     *
     * @param int|null $storeId
     * @return array
     */
    private function getPaymentTerms(?int $storeId = null): array
    {
        $termsType = $this->configRepository->getPaymentTermsType($storeId);
        $defaultDays = $this->configRepository->getDefaultPaymentTerm($storeId);

        $terms = [
            'type' => 'NET_TERMS',
            'duration_days' => $defaultDays,
        ];

        if ($termsType === 'end_of_month') {
            $terms['duration_days_calculated_from'] = 'END_OF_MONTH';
        }

        return $terms;
    }

    /**
     * Get all available buyer terms for the checkout term selector.
     *
     * @param int|null $storeId
     * @return array
     */
    private function getAvailableBuyerTerms(?int $storeId = null): array
    {
        $termsType = $this->configRepository->getPaymentTermsType($storeId);
        $allTerms = $this->configRepository->getAllBuyerTerms($storeId);

        $available = [];
        foreach ($allTerms as $days) {
            $term = [
                'type' => 'NET_TERMS',
                'duration_days' => $days,
            ];
            if ($termsType === 'end_of_month') {
                $term['duration_days_calculated_from'] = 'END_OF_MONTH';
            }
            $available[] = $term;
        }

        return $available;
    }
}
