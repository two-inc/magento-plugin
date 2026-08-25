<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Service\Order;

use Magento\Catalog\Helper\Image;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollection;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Exception\InputException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Url;
use Magento\Sales\Api\OrderItemRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Store\Model\App\Emulation;
use Magento\Tax\Api\OrderTaxManagementInterface;
use Two\Gateway\Api\Config\RepositoryInterface as ConfigRepository;
use Two\Gateway\Api\Log\RepositoryInterface as LogRepository;
use Two\Gateway\Service\Fee\FeeLineProviderPool;
use Two\Gateway\Service\Order as OrderService;

/**
 * Compose Order Service
 */
class ComposeOrder extends OrderService
{
    /**
     * @var CheckoutSession
     */
    private $checkoutSession;

    public function __construct(
        Image $imageHelper,
        ConfigRepository $configRepository,
        CategoryCollection $categoryCollectionFactory,
        OrderItemRepositoryInterface $orderItemRepository,
        Emulation $appEmulation,
        Url $url,
        LogRepository $logRepository,
        CheckoutSession $checkoutSession,
        FeeLineProviderPool $feeLineProviderPool,
        OrderTaxManagementInterface $orderTaxManagement
    ) {
        parent::__construct(
            $imageHelper,
            $configRepository,
            $categoryCollectionFactory,
            $orderItemRepository,
            $appEmulation,
            $url,
            $logRepository,
            $feeLineProviderPool,
            $orderTaxManagement
        );
        $this->checkoutSession = $checkoutSession;
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

        // Prefer the persisted order columns (populated by the conversion
        // fieldset) over the session. Session is the source of truth for the
        // chip-render flow before placement, but by the time ComposeOrder
        // runs the order has been converted from the quote and the columns
        // are authoritative. Session can drift if multi-tab logout / GC /
        // a custom plugin clears it between collectTotals and place().
        $surchargeAmount = (float)$order->getTwoSurchargeAmount();
        $surchargeTax = (float)$order->getTwoSurchargeTaxAmount();
        $description = (string)$order->getTwoSurchargeDescription();
        $taxRatePercent = (float)$order->getTwoSurchargeTaxRate();

        if ($surchargeAmount <= 0) {
            // Fallback to session for orders placed in the brief window where
            // a buyer's order conversion runs without the columns populated
            // (e.g. mid-deploy before the data patch lands). Remove this
            // fallback once we're confident every placement persists.
            $surchargeAmount = (float)$this->checkoutSession->getTwoSurchargeAmount();
            $surchargeTax = (float)$this->checkoutSession->getTwoSurchargeTax();
            $description = $this->checkoutSession->getTwoSurchargeDescription() ?: '';
            $taxRatePercent = (float)$this->checkoutSession->getTwoSurchargeTaxRate();
        }

        if ($surchargeAmount > 0) {
            $description = $description ?: (string)__('Payment terms fee');
            $taxRate = $taxRatePercent / 100;

            $lineItems[] = [
                'order_item_id' => 'surcharge',
                'name' => $description,
                'description' => $description,
                'type' => 'BUYER_FEE',
                'image_url' => '',
                'product_page_url' => '',
                'gross_amount' => $this->roundAmt($surchargeAmount + $surchargeTax),
                'net_amount' => $this->roundAmt($surchargeAmount),
                'tax_amount' => $this->roundAmt($surchargeTax),
                'discount_amount' => '0.00',
                'tax_rate' => $this->roundAmt($taxRate, 6),
                'tax_class_name' => 'VAT ' . $this->roundAmt($taxRatePercent) . '%',
                'unit_price' => $this->roundAmt($surchargeAmount, 6),
                'quantity' => 1,
                'quantity_unit' => 'sc',
            ];
        }

        // Grand total already includes surcharge from Total Collector
        $grossTotal = (float)$order->getGrandTotal();
        $taxTotal = (float)$order->getTaxAmount();
        $netTotal = $grossTotal - $taxTotal;

        // Reconcile any known third-party fee (via a registered
        // FeeLineProviderInterface) and, failing that, any genuinely
        // untaxed residual. See Order::reconcileOtherCharges() docblock.
        $lineItems = $this->reconcileOtherCharges($lineItems, $order, $grossTotal, $taxTotal);

        // Last gate before the amounts go on the wire: every line's declared
        // tax has to follow from its own declared rate and net.
        $this->validateTaxReconciliation($lineItems);

        // Compose the final payload for the API call. Fields that may
        // legitimately be blank are NOT listed here — they go through the
        // omit-when-blank list below.
        $payload = [
            'billing_address' => $this->getAddress($order, $additionalData, 'billing'),
            'shipping_address' => $this->getAddress($order, $additionalData, 'shipping'),
            'buyer' => $this->getBuyer($order, $additionalData),
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
                // merchant_edit_order_url is deliberately absent: the plugin
                // exposes no merchant-side edit-order route, so there is no
                // URL to put here.
                'merchant_order_verification_failed_url' => $this->url->getUrl(
                    'two/payment/verificationfailed',
                    ['_two_order_reference' => base64_encode($orderReference)]
                ),
            ],
        ];

        // TWO-25386: these fields are optional and are omitted rather than sent
        // blank. Two different reasons, both real.
        //
        // vendor_name is the one that cannot be blank while the key is present:
        // a blank admin setting sent as '' had the order rejected and nothing
        // created at all, and that rejection is why this ticket exists.
        //
        // The other four never caused a rejection. They are omitted because of
        // what an omitted key means to a later order edit; the field
        // constraints and the edit-merge semantics behind that are recorded on
        // TWO-25386. The decision here is that a value nobody set is left out
        // of the request entirely. Concretely, composing them blank meant an
        // admin order-address edit sent buyer_purchase_order_number and
        // order_note blank (buyer_department and buyer_project were forwarded
        // from the stored additional_information, so those two survived).
        //
        // An explicit list of what to omit when blank, never a blanket
        // empty-strip over $payload: merchant_confirmation_url and the amount
        // fields have to survive regardless of their value.
        $optionalFields = [
            'buyer_department' => $additionalData['department'] ?? '',
            'buyer_project' => $additionalData['project'] ?? '',
            'buyer_purchase_order_number' => $additionalData['poNumber'] ?? '',
            'order_note' => $additionalData['orderNote'] ?? '',
            'vendor_name' => $this->configRepository->getVendorSiteName($storeId),
        ];
        foreach ($optionalFields as $key => $value) {
            // $additionalData comes straight from the checkout request, and the
            // observer that stores it only checks that the top level is an
            // array — so a crafted request can leave an array sitting in one of
            // these values. Casting that to string raises a warning, which
            // developer mode turns into a failed order placement, so drop
            // anything that is not a scalar instead.
            if (!is_scalar($value)) {
                continue;
            }
            // Compare as string rather than using empty(), so a legitimate
            // '0' department/project reference is still sent.
            if ((string)$value !== '') {
                $payload[$key] = (string)$value;
            }
        }

        // Add invoice_details only if invoiceEmails are present. The payment
        // reference fields are left out for one reason: the plugin has no value
        // to put in them, and they are defaulted when the key is absent. Their
        // constraints are recorded on TWO-25386.
        if (!empty($additionalData['invoiceEmails'])) {
            $payload['invoice_details'] = [
                'invoice_emails' => explode(',', $additionalData['invoiceEmails']),
            ];
        }

        return $payload;
    }


    /**
     * Get the buyer's selected term from checkout, validated against configured terms.
     *
     * A term the buyer picked but the merchant no longer offers is refused,
     * not quietly swapped for the default (TWO-25503): the buyer agreed to
     * pay on a specific term, and placing the order on a different one is a
     * changed contract they never saw. Same check and same failure mode as
     * the chip-click endpoint (Model\Webapi\TermSelection).
     *
     * No selection at all is a different case — the checkout simply never
     * sent one, so the default term applies.
     *
     * The term is also cross-checked against the one the SURCHARGE was
     * priced on. Two independent sources reach placement: the payload's term
     * comes from `additionalData`, while Model\Total\Surcharge prices the fee
     * off the session term that `/select-term` writes. A `/select-term` call
     * that failed mid-flow leaves them disagreeing, and the order would then
     * be placed on one term carrying the other one's fee. Only enforced when
     * the session actually holds a term — a cleared session (multi-tab
     * logout, GC) has nothing to compare and must not fail a valid order.
     *
     * @throws InputException when the selected term is unavailable, not
     *                        numeric, or disagrees with the priced term
     */
    private function getSelectedTermDays(array $additionalData, ?int $storeId = null): int
    {
        $raw = $additionalData['selectedTerm'] ?? null;
        if ($raw !== null && $raw !== '' && !is_numeric($raw)) {
            // A non-numeric term casts to 0 and silently takes the default —
            // a changed contract, so refuse it like an unavailable one.
            $this->logRepository->addErrorLog(
                'NonNumericPaymentTerm',
                sprintf('Selected payment term is not numeric for store %d.', (int)$storeId)
            );
            throw new InputException(__('Selected payment term is not available.'));
        }

        $selected = (int)$raw;
        if ($selected > 0 && !$this->configRepository->isBuyerTermAvailable($selected, $storeId)) {
            $this->logRepository->addErrorLog(
                'UnavailablePaymentTerm',
                sprintf('Selected payment term %d is not offered for store %d.', $selected, (int)$storeId)
            );
            throw new InputException(__('Selected payment term is not available.'));
        }

        $resolved = $selected > 0 ? $selected : $this->configRepository->getDefaultPaymentTerm($storeId);

        $pricedTerm = (int)$this->checkoutSession->getTwoSelectedTerm();
        if ($pricedTerm > 0 && $pricedTerm !== $resolved) {
            $this->logRepository->addErrorLog(
                'PaymentTermMismatch',
                sprintf(
                    'Order composes term %d but the surcharge was priced on term %d for store %d.',
                    $resolved,
                    $pricedTerm,
                    (int)$storeId
                )
            );
            throw new InputException(__('Selected payment term is not available.'));
        }

        return $resolved;
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
