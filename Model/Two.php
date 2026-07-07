<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Model;

use Exception;
use Magento\Framework\Api\AttributeValueFactory;
use Magento\Framework\Api\ExtensionAttributesFactory;
use Magento\Config\Model\ResourceModel\Config\Data\CollectionFactory as ConfigDataCollectionFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Phrase;
use Magento\Framework\Registry;
use Magento\Payment\Helper\Data;
use Magento\Payment\Model\InfoInterface;
use Magento\Payment\Model\Method\AbstractMethod;
use Magento\Payment\Model\Method\Logger;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Sales\Api\OrderStatusHistoryRepositoryInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Status\HistoryFactory;
use Two\Gateway\Api\BrandRegistryInterface;
use Two\Gateway\Api\Config\RepositoryInterface as ConfigRepository;
use Two\Gateway\Service\Api\Adapter;
use Two\Gateway\Service\Order\ComposeCapture;
use Two\Gateway\Service\Order\ComposeOrder;
use Two\Gateway\Service\Order\ComposeRefund;
use Two\Gateway\Service\Order\MinimumOrderGate;
use Two\Gateway\Service\Order\MinimumOrderProvider;
use Two\Gateway\Service\UrlCookie;
use Two\Gateway\Api\Log\RepositoryInterface as LogRepository;

/**
 * Two Payment Model
 */
class Two extends AbstractMethod
{
    public const CODE = 'two_payment';

    public const STATUS_NEW = 'two_new';
    public const STATUS_FAILED = 'two_failed';
    public const STATUS_PENDING = 'pending_two_payment';
    /**
     * @var RequestInterface
     */
    public $request;
    protected $_code = self::CODE;
    /**
     * @var bool
     */
    protected $_canUseInternal = false;
    /**
     * @var bool
     */
    protected $_canVoid = true;
    /**
     * @var bool
     */
    protected $_isGateway = true;
    /**
     * @var bool
     */
    protected $_canRefund = true;
    /**
     * @var bool
     */
    protected $_canCapture = true;
    /**
     * @var bool
     */
    protected $_canRefundInvoicePartial = true;
    /**
     * @var ConfigRepository
     */
    private $configRepository;

    /** @var BrandRegistryInterface */
    private $brandRegistry;
    /**
     * @var UrlCookie
     */
    private $urlCookie;
    /**
     * @var ComposeOrder
     */
    private $compositeOrder;
    /**
     * @var ComposeRefund
     */
    private $composeRefund;
    /**
     * @var ComposeCapture
     */
    private $composeCapture;
    /**
     * @var Adapter
     */
    private $apiAdapter;
    /**
     * @var HistoryFactory
     */
    private $historyFactory;

    /**
     * @var OrderStatusHistoryRepositoryInterface
     */
    private $orderStatusHistoryRepository;
    /**
     * @var OrderRepositoryInterface
     */
    private $orderRepository;
    /**
     * @var LogRepository
     */
    private $logRepository;
    /**
     * @var MinimumOrderGate
     */
    private $minimumOrderGate;
    /**
     * @var MinimumOrderProvider
     */
    private $minimumOrderProvider;
    /**
     * @var ConfigDataCollectionFactory
     */
    private $configDataCollectionFactory;
    /**
     * Per-store memo for isAmastyCheckoutStore(); isAvailable() fires many
     * times per page and the detection reads config + core_config_data.
     *
     * @var array<int, bool>
     */
    private $amastyCheckoutStore = [];

    /**
     * Two constructor.
     *
     * @param RequestInterface $request
     * @param Context $context
     * @param Registry $registry
     * @param ExtensionAttributesFactory $extensionFactory
     * @param AttributeValueFactory $customAttributeFactory
     * @param ConfigRepository $configRepository
     * @param Data $paymentData
     * @param ScopeConfigInterface $scopeConfig
     * @param Logger $logger
     * @param UrlCookie $urlCookie
     * @param ComposeOrder $composeOrder
     * @param ComposeRefund $composeRefund
     * @param ComposeCapture $composeCapture
     * @param HistoryFactory $historyFactory
     * @param OrderStatusHistoryRepositoryInterface $orderStatusHistoryRepository
     * @param Adapter $apiAdapter
     * @param LogRepository $logRepository
     * @param MinimumOrderGate $minimumOrderGate
     * @param MinimumOrderProvider $minimumOrderProvider
     * @param ConfigDataCollectionFactory $configDataCollectionFactory
     * @param AbstractResource|null $resource
     * @param AbstractDb|null $resourceCollection
     * @param array $data
     */
    public function __construct(
        RequestInterface $request,
        Context $context,
        Registry $registry,
        ExtensionAttributesFactory $extensionFactory,
        AttributeValueFactory $customAttributeFactory,
        ConfigRepository $configRepository,
        BrandRegistryInterface $brandRegistry,
        Data $paymentData,
        ScopeConfigInterface $scopeConfig,
        Logger $logger,
        UrlCookie $urlCookie,
        ComposeOrder $composeOrder,
        ComposeRefund $composeRefund,
        ComposeCapture $composeCapture,
        HistoryFactory $historyFactory,
        OrderStatusHistoryRepositoryInterface $orderStatusHistoryRepository,
        OrderRepositoryInterface $orderRepository,
        Adapter $apiAdapter,
        LogRepository $logRepository,
        MinimumOrderGate $minimumOrderGate,
        MinimumOrderProvider $minimumOrderProvider,
        ConfigDataCollectionFactory $configDataCollectionFactory,
        ?AbstractResource $resource = null,
        ?AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        $this->request = $request;
        parent::__construct(
            $context,
            $registry,
            $extensionFactory,
            $customAttributeFactory,
            $paymentData,
            $scopeConfig,
            $logger,
            $resource,
            $resourceCollection,
            $data
        );
        $this->configRepository = $configRepository;
        $this->brandRegistry = $brandRegistry;
        $this->urlCookie = $urlCookie;
        $this->compositeOrder = $composeOrder;
        $this->composeRefund = $composeRefund;
        $this->composeCapture = $composeCapture;
        $this->apiAdapter = $apiAdapter;
        $this->historyFactory = $historyFactory;
        $this->orderStatusHistoryRepository = $orderStatusHistoryRepository;
        $this->orderRepository = $orderRepository;
        $this->logRepository = $logRepository;
        $this->minimumOrderGate = $minimumOrderGate;
        $this->minimumOrderProvider = $minimumOrderProvider;
        $this->configDataCollectionFactory = $configDataCollectionFactory;
    }

    /**
     * Authorize the transaction
     *
     * @param InfoInterface $payment
     * @param float $amount
     *
     * @return $this|Two
     *
     * @throws LocalizedException
     */
    public function authorize(InfoInterface $payment, $amount)
    {
        $order = $payment->getOrder();
        $this->assertOrderMeetsMinimum($order);
        $this->urlCookie->delete();
        $orderReference = (string)rand();

        $additionalInformation = $payment->getAdditionalInformation();

        $payload = $this->compositeOrder->execute(
            $order,
            $orderReference,
            $additionalInformation
        );

        // Create order
        $response = $this->apiAdapter->execute('/v1/order', $payload);
        $error = $this->getErrorFromResponse($response);
        if ($error) {
            throw new LocalizedException($error);
        }

        if ($response['status'] !== 'APPROVED') {
            $this->logRepository->addDebugLog(
                sprintf('Order was not accepted by %s', $this->brandRegistry->getProductName()),
                $response
            );
            // Surface the minimum when the decline is attributable to it:
            // primarily by the API's machine-readable decline reason
            // (emitted by the platform's minimum-order rejection), with a
            // strictly-below-minimum check on the order value as fallback
            // while older backends carry only a generic reason.
            $storeId = $order->getStoreId() !== null ? (int)$order->getStoreId() : null;
            $orderCurrency = (string)$order->getOrderCurrencyCode();
            $minimumOrder = $this->minimumOrderProvider->getMinimum($storeId);
            $declinedOnMinimum = ($response['decline_reason'] ?? null) === 'ORDER_BELOW_MIN_INVOICE_AMOUNT';
            if (!$declinedOnMinimum && $minimumOrder !== null) {
                $orderValue = $minimumOrder['basis'] === 'gross'
                    ? (float)$order->getGrandTotal()
                    : (float)$order->getGrandTotal() - (float)$order->getTaxAmount();
                $declinedOnMinimum = $this->minimumOrderGate->isBelowMinimum(
                    $minimumOrder,
                    $orderValue,
                    $orderCurrency,
                    $storeId
                );
            }
            if ($declinedOnMinimum && $minimumOrder !== null) {
                $display = $this->minimumOrderGate->getMinimumForDisplay($minimumOrder, $orderCurrency, $storeId);
                if ($display !== null) {
                    throw new LocalizedException($this->minimumOrderMessage($display, $order));
                }
            }
            throw new LocalizedException(
                __('Invoice purchase with %1 is not available for this order.', $this->brandRegistry->getProductName())
            );
        }

        $twoOrderId = $response['id'];
        $order->setTwoOrderId($twoOrderId);
        $order->setTwoOrderReference($orderReference);
        $payload['gateway_data']['external_order_status'] = $response['external_order_status'];
        $payload['gateway_data']['original_order_id'] = $response['original_order_id'];
        $payload['gateway_data']['state'] = $response['state'];
        $payload['gateway_data']['status'] = $response['status'];

        //remove unnecessary data before save in database
        unset($payload['line_items']);
        unset($payload['shipping_address']);
        unset($payload['billing_address']);
        unset($payload['merchant_urls']);

        $payment->setAdditionalInformation($payload);
        $payment->setTransactionId($twoOrderId)
            ->setIsTransactionClosed(0)
            ->setIsTransactionPending(true);
        $paymentUrl = $response['payment_url'];
        $brandParams = [];
        $brand = $this->configRepository->getBrand();
        if ($brand !== '') {
            $brandParams['brand'] = $brand;
        }
        $brandVersion = $this->configRepository->getBrandVersion();
        if ($brandVersion !== '') {
            $brandParams['brandVersion'] = $brandVersion;
        }
        if ($brandParams) {
            $separator = strpos($paymentUrl, '?') !== false ? '&' : '?';
            $paymentUrl .= $separator . http_build_query($brandParams);
        }
        $this->urlCookie->set($paymentUrl);
        return $this;
    }

    /**
     * Title for storefront payment-method list, admin order display
     * and order/invoice/creditmemo line items.
     *
     * Resolution order:
     *   1. `payment/<code>/title` from CCD (admin-saved override).
     *   2. BrandRegistry::getProductName() (brand overlay fallback).
     *
     * Optionally suffixed with the buyer's selected term in days
     * (e.g. "Partner Product - 60 days"). DataAssignObserver
     * stores the chosen term under the `selectedTerm` key as a
     * scalar; the duration is wrapped in a separate __() call so the
     * existing "%1 days" CSV entry handles localisation.
     */
    public function getTitle()
    {
        try {
            $info = $this->getInfoInstance();
            $days = (int)$info->getAdditionalInformation('selectedTerm');
        } catch (\Exception $e) {
            $days = 0;
        }
        $configured = (string)$this->getConfigData('title');
        $noun = $configured !== ''
            ? __($configured)
            : __($this->brandRegistry->getProductName());
        if ($days > 0) {
            return sprintf('%s - %s', $noun, __('%1 days', $days));
        }
        return (string)$noun;
    }

    /**
     *
     *
     * @param Phrase $message
     * @param $traceID|null
     *
     * @return Phrase
     */
    private function _getMessageWithTrace($message, $traceID): Phrase
    {
        if ($traceID == null) {
            return $message;
        }
        return __('%1 [Trace ID: %2]', $message, $traceID);
    }

    /**
     * Get error from response
     *
     * @param $response
     *
     * @return Phrase|null
     */
    public function getErrorFromResponse(array $response): ?Phrase
    {
        $tryAgainLater = __('Please try again later.');
        $generalError = __(
            'Something went wrong with your request to %1. %2',
            $this->brandRegistry->getProductName(),
            $tryAgainLater
        );
        if (!$response || !is_array($response)) {
            return $generalError;
        }

        $traceID = null;
        if (array_key_exists('error_trace_id', $response)) {
            $traceID = $response['error_trace_id'];
        }

        $isClientError = isset($response['http_status']) && $response['http_status'] == 400;

        // Validation errors — user-facing, no trace ID
        if ($isClientError && isset($response['error_json']) && is_array($response['error_json'])) {
            $errs = [];
            foreach ($response['error_json'] as $err) {
                $fieldName = isset($err['loc'])
                    ? $this->getFieldNameFromLoc(json_encode($err['loc']))
                    : null;
                $msg = isset($err['msg']) ? $this->cleanValidationMessage($err['msg']) : null;

                if ($fieldName && $msg) {
                    $errs[] = __('%1: %2.', $fieldName, rtrim($msg, '.'));
                } elseif ($fieldName) {
                    $errs[] = __('%1 is not valid.', $fieldName);
                } elseif ($msg) {
                    $errs[] = $msg;
                }
            }
            if (count($errs) > 0) {
                // Wrap as a Phrase without re-running translation: each
                // entry in $errs is already __()-translated.
                return __('%1', join(' ', $errs));
            }
        }

        if (isset($response['error_code'])) {
            $errorCode = $response['error_code'];
            $reason = $response['error_message'];

            // User errors — no trace ID
            if ($errorCode == 'SAME_BUYER_SELLER_ERROR') {
                $reason = __('The buyer and the seller are the same company.');
            }
            if ($isClientError && in_array($errorCode, ['SCHEMA_ERROR', 'SAME_BUYER_SELLER_ERROR', 'ORDER_INVALID'])) {
                return $reason instanceof Phrase ? $reason : __($reason);
            }

            // System errors — include trace ID
            $message = __(
                'Your request to %1 failed. Reason: %2',
                $this->brandRegistry->getProductName(),
                $reason
            );
            return $this->_getMessageWithTrace($message, $traceID);
        }

        return null;
    }

    /**
     * Get human-readable field name from a pydantic error loc array.
     *
     * @param string $locStr JSON-encoded loc array, e.g. '["buyer","representative","phone_number"]'
     * @return Phrase|null
     */
    public function getFieldNameFromLoc(string $locStr): ?Phrase
    {
        static $fieldNames = null;
        if ($fieldNames === null) {
            $fieldNames = [
                '["buyer","representative","phone_number"]' => __('Phone Number'),
                '["buyer","company","organization_number"]' => __('Company Number'),
                '["buyer","representative","first_name"]' => __('First Name'),
                '["buyer","representative","last_name"]' => __('Last Name'),
                '["buyer","representative","email"]' => __('Email Address'),
                '["billing_address","street_address"]' => __('Street Address'),
                '["billing_address","city"]' => __('City'),
                '["billing_address","country"]' => __('Country'),
                '["billing_address","postal_code"]' => __('Zip/Postal Code'),
            ];
        }
        $locStr = preg_replace('/\s+/', '', $locStr);
        return $fieldNames[$locStr] ?? null;
    }

    /**
     * Clean up a pydantic validation message for user display.
     *
     * @param string $msg Raw message, e.g. "Value error, Invalid phone number for GB: 00123456789"
     * @return string Cleaned message, e.g. "Invalid phone number for GB: 00123456789"
     */
    private function cleanValidationMessage(string $msg): string
    {
        $msg = preg_replace('/^Value error,\s*/i', '', $msg);
        $msg = preg_replace('/\s*\[type=.*$/', '', $msg);
        return trim($msg);
    }

    /**
     * @inheritDoc
     */
    public function void(InfoInterface $payment)
    {
        return $this->cancel($payment);
    }

    /**
     * @inheritDoc
     */
    public function cancel(InfoInterface $payment)
    {
        /** @var Order $order */
        $order = $payment->getOrder();
        try {
            $twoOrderId = $order->getTwoOrderId();
            $response = $this->apiAdapter->execute('/v1/order/' . $order->getTwoOrderId() . '/cancel');
            if ($response) {
                $error = $this->getErrorFromResponse($response);
                $comment = __(
                    'Could not update %1 order status to cancelled. ' .
                    'Please contact support with order ID %2. Error: %3',
                    $this->brandRegistry->getProductName(),
                    $twoOrderId,
                    $error
                );
                $order->addStatusToHistory($order->getStatus(), $comment->render());
            } else {
                $order->addStatusToHistory(
                    $order->getStatus(),
                    __('%1 order has been marked as cancelled', $this->brandRegistry->getProductName())
                );
            }

            $this->orderRepository->save($order);
        } catch (LocalizedException $e) {
            $order->addStatusToHistory($order->getStatus(), $e->getMessage());
        }

        return $this;
    }

    private function isWholeOrderInvoiced(OrderInterface $order): bool
    {
        foreach ($order->getAllVisibleItems() as $orderItem) {
            /** @var Order\Item $orderItem */
            if ($orderItem->getQtyInvoiced() < $orderItem->getQtyOrdered()) {
                return false;
            }
        }

        return true;
    }

    /**
     * @inheritDoc
     */
    public function capture(InfoInterface $payment, $amount)
    {
        if ($this->canCapture()) {
            /** @var Order $order */
            $order = $payment->getOrder();
            $twoOrderId = $order->getTwoOrderId();
            if (!$twoOrderId) {
                throw new LocalizedException(
                    __('Could not initiate capture with %1', $this->brandRegistry->getProductName())
                );
            }

            $payload = [];
            $isWholeOrderInvoiced = $this->isWholeOrderInvoiced($order);
            $isPartialOrder = !$isWholeOrderInvoiced;
            while (true) {
                if ($isPartialOrder) {
                    $invoices = $order->getInvoiceCollection();
                    $totalInvoices = count($invoices);
                    $cnt = 1;
                    $createdInvoice = null;
                    foreach ($invoices as $invoice) {
                        if ($cnt == $totalInvoices) {
                            $createdInvoice = $invoice;
                        }
                        $cnt++;
                    }
                    $payload = [
                        'partial' => $this->composeCapture->execute($createdInvoice),
                    ];
                }
                $response = $this->apiAdapter->execute('/v1/order/' . $twoOrderId . '/fulfillments', $payload);
                $error = $this->getErrorFromResponse($response);

                if ($error) {
                    if ($response['error_code'] == 'PARTIAL_ORDER_MISSING_DATA') {
                        $isPartialOrder = true;
                        continue;
                    }
                    throw new LocalizedException($error);
                }
                break;
            }

            if (!empty($response) && isset($response['fulfilled_order']['id'])) {
                $payment->setTransactionId($response['fulfilled_order']['id'])->setIsTransactionClosed(0);
            } else {
                $payment->setIsTransactionClosed(0);
            }
            $payment->save();

            $this->parseFulfillResponse($response, $order);
        } else {
            throw new LocalizedException(__('The capture action is not available.'));
        }
        return $this;
    }

    /**
     * @inheritDoc
     */
    public function canCapture()
    {
        return $this->_canCapture && ($this->configRepository->getFulfillTrigger() == 'invoice');
    }

    /**
     * Parse Fulfill Response
     *
     * @param array $response
     * @param Order $order
     * @return void
     * @throws Exception
     */
    private function parseFulfillResponse(array $response, Order $order): void
    {
        if (empty($response['fulfilled_order'] ||
            empty($response['fulfilled_order']['id']))) {
            return;
        }
        $additionalInformation = $order->getPayment()->getAdditionalInformation();
        $additionalInformation['marked_completed'] = true;

        $order->getPayment()->setAdditionalInformation($additionalInformation);

        if (empty($response['remained_order'])) {
            $comment = __(
                '%1 order marked as completed.',
                $this->brandRegistry->getProductName(),
            );
        } else {
            $comment = __(
                '%1 order marked as partially completed.',
                $this->brandRegistry->getProductName(),
            );
        }

        $this->addStatusToOrderHistory($order, $comment->render());
    }

    /**
     * Add order status to history
     *
     * @param Order $order
     * @param string $comment
     * @throws Exception
     */
    private function addStatusToOrderHistory(Order $order, string $comment)
    {
        $history = $this->historyFactory->create();
        $history->setParentId($order->getEntityId())
            ->setComment($comment)
            ->setEntityName('order')
            ->setStatus($order->getStatus());
        $this->orderStatusHistoryRepository->save($history);
    }

    /**
     * @inheritDoc
     */
    public function refund(InfoInterface $payment, $amount)
    {
        $order = $payment->getOrder();
        $twoOrderId = $order->getTwoOrderId();
        if (!$twoOrderId) {
            throw new LocalizedException(
                __('Could not initiate refund with %1', $this->brandRegistry->getProductName()),
            );
        }

        $billingAddress = $order->getBillingAddress();

        $payload = $this->composeRefund->execute(
            $payment->getCreditmemo(),
            (float)$amount,
            $order
        );
        $response = $this->apiAdapter->execute(
            "/v1/order/" . $twoOrderId . "/refund",
            $payload
        );

        $error = $this->getErrorFromResponse($response);
        if ($error) {
            $this->addOrderComment($order, $error);
            throw new LocalizedException($error);
        }

        if (!$response['amount']) {
            $reason = __('Amount is missing');
            $message = __(
                'Failed to refund order with %1. Reason: %2',
                $this->brandRegistry->getProductName(),
                $reason
            );
            $this->addOrderComment($order, $message);
            throw new LocalizedException(
                $message
            );
        }

        $comment = __(
            'Successfully refunded order with %1 for order ID: %2. Refund reference: %3',
            $this->brandRegistry->getProductName(),
            $twoOrderId,
            $response['refund_no']
        );
        $order->addStatusToHistory($order->getStatus(), $comment->render())->save();
        return $this;
    }

    /**
     * @param Order $order
     * @param $message
     */
    public function addOrderComment(Order $order, $message)
    {
        $order->addStatusToHistory($order->getStatus(), $message);
    }

    /**
     * @inheritDoc
     *
     * The active + api-key check must be method-code-bound (`_code`), not
     * brand-aware. Both `two_payment` and an overlay's method (e.g.
     * `acme_payment`) can be registered side-by-side; routing this method's
     * self-check through the brand-aware ConfigRepository would make Two's
     * instance answer with the overlay's config (because the brand-aware
     * fallback resolves to the install's active brand, not this instance's
     * `_code`). Use Magento's base `_code`-bound `isAvailable()` for the
     * active flag and read api_key directly off `_code` for the same reason.
     */
    public function isAvailable(?CartInterface $quote = null)
    {
        if (!parent::isAvailable($quote)) {
            return false;
        }
        $apiKey = $this->_scopeConfig->getValue('payment/' . $this->_code . '/api_key');
        if ($apiKey === null || $apiKey === '') {
            return false;
        }
        // Platform minimum-order constraint (the API-resolved tuple from
        // GET /v1/merchant - the same value checkout-api enforces at order
        // create/intent) plus the merchant's own optional minimum (admin
        // setting in the STORE BASE currency; validated on save to meet or
        // exceed the platform floor converted to that currency).
        $storeId = null;
        $store = null;
        if ($quote instanceof \Magento\Quote\Model\Quote) {
            $store = $quote->getStore();
            if ($quote->getStoreId() !== null) {
                $storeId = (int)$quote->getStoreId();
            }
        }
        // Amasty OneStepCheckout persists the buyer's shipping method to the
        // server quote only at order placement, so at checkout-render time the
        // server quote is blind to the live shipping choice and this gate would
        // judge a stale total (a dearer shipping that crosses the minimum never
        // registers server-side). On an Amasty store view we therefore OFFER the
        // method unconditionally here and gate its visibility client-side
        // against the live total (see Model\Ui\ConfigProvider + the renderer).
        // Enforcement is not waived, only deferred: authorize() re-checks the
        // finalised order total (shipping now known) against BOTH the platform
        // and merchant minimums fail-closed at placement, and checkout-api
        // independently enforces the platform floor. isAmastyCheckoutStore()
        // requires an explicit admin override, not Amasty's inherited config.xml
        // default, so the bypass cannot leak onto other checkouts.
        if ($this->isAmastyCheckoutStore($store, $storeId)) {
            return true;
        }
        $platformMinimum = $this->minimumOrderProvider->getMinimum($storeId);
        $merchantMinimum = $store !== null
            ? $this->buildMerchantMinimum((string)$store->getBaseCurrencyCode(), $platformMinimum, $storeId)
            : null;
        return $this->minimumOrderGate->isSatisfied($platformMinimum, $quote, $merchantMinimum);
    }

    /**
     * The client-side visibility inputs for the Two method on a quote: the
     * active minimum-order constraints (platform + merchant, the same pair
     * isAvailable() enforces) projected into the quote's DISPLAY currency so
     * the renderer only has to compare — no rule/FX logic client-side — plus
     * whether any active minimum could NOT be projected (missing FX rate).
     *
     * On `unresolved`, the renderer must HIDE the method rather than show it
     * for want of a number: this mirrors MinimumOrderGate's fail-closed stance
     * (a minimum we cannot prove satisfied hides the method) so the client gate
     * does not fail OPEN where the server gate would fail closed.
     *
     * @return array{minimums: array<int, array{amount: float, basis: string}>, unresolved: bool}
     */
    public function getMinimumOrderVisibility(?CartInterface $quote): array
    {
        $empty = ['minimums' => [], 'unresolved' => false];
        if (!$quote instanceof \Magento\Quote\Model\Quote) {
            return $empty;
        }
        $storeId = $quote->getStoreId() !== null ? (int)$quote->getStoreId() : null;
        $store = $quote->getStore();
        $baseCurrency = $store !== null ? (string)$store->getBaseCurrencyCode() : '';
        $displayCurrency = (string)($quote->getQuoteCurrencyCode() ?: $baseCurrency);
        if ($displayCurrency === '') {
            return $empty;
        }

        $minimums = [];
        $unresolved = false;
        $platform = $this->minimumOrderProvider->getMinimum($storeId);
        $active = [$platform, $this->buildMerchantMinimum($baseCurrency, $platform, $storeId)];
        foreach ($active as $minimum) {
            if ($minimum === null) {
                continue;
            }
            $shown = $this->minimumOrderGate->getMinimumForDisplay($minimum, $displayCurrency, $storeId);
            if ($shown === null) {
                $unresolved = true;
                continue;
            }
            $minimums[] = $shown;
        }

        return ['minimums' => $minimums, 'unresolved' => $unresolved];
    }

    /**
     * The merchant's own optional minimum-order tuple, in the store BASE
     * currency, or null when unset (<= 0) or the base currency is unknown.
     * Single source of truth shared by isAvailable()'s server gate,
     * getMinimumOrderVisibility()'s client-display projection, and the
     * authorize() placement backstop: they MUST agree on the constraint, so
     * the construction lives in exactly one place. Amount is validated on save
     * to meet/exceed the platform floor; basis falls back to the platform
     * minimum's basis, then 'gross', when the admin value is neither 'net' nor
     * 'gross'.
     *
     * @param string $baseCurrency Store base currency the merchant amount is denominated in.
     * @param array<string, mixed>|null $platform Platform minimum, for basis fallback only.
     * @param int|null $storeId Scope for the admin config reads.
     * @return array{amount: float, currency: string, basis: string}|null
     */
    private function buildMerchantMinimum(string $baseCurrency, ?array $platform, ?int $storeId = null): ?array
    {
        $merchantValue = (float)$this->getConfigData('merchant_minimum_order', $storeId);
        if ($merchantValue <= 0 || $baseCurrency === '') {
            return null;
        }
        $merchantBasis = (string)$this->getConfigData('merchant_minimum_order_basis', $storeId);
        return [
            'amount' => $merchantValue,
            'currency' => $baseCurrency,
            'basis' => in_array($merchantBasis, ['net', 'gross'], true)
                ? $merchantBasis
                : ($platform['basis'] ?? 'gross'),
        ];
    }

    /**
     * Fail-closed server backstop: reject a finalised order below the platform
     * or merchant minimum at placement. A normal buyer never reaches this — the
     * checkout renderer's client-side gate (and isAvailable() on non-Amasty
     * checkouts) already hides the method below the minimum. It catches the
     * paths that evade the client gate: JS disabled, direct API calls, or a
     * total that dropped after the method was selected. It is also the SOLE
     * server enforcer of the MERCHANT minimum on Amasty, where isAvailable() is
     * bypassed and the order total (with shipping) is only complete here at
     * placement; checkout-api independently enforces the platform floor but
     * never receives the merchant's own admin minimum.
     *
     * @throws LocalizedException when the finalised order is below a minimum.
     */
    private function assertOrderMeetsMinimum(Order $order): void
    {
        $storeId = $order->getStoreId() !== null ? (int)$order->getStoreId() : null;
        $orderCurrency = (string)$order->getOrderCurrencyCode();
        $store = $order->getStore();
        $baseCurrency = $store !== null ? (string)$store->getBaseCurrencyCode() : '';
        $platform = $this->minimumOrderProvider->getMinimum($storeId);
        $active = [$platform, $this->buildMerchantMinimum($baseCurrency, $platform, $storeId)];
        foreach ($active as $minimum) {
            if ($minimum === null) {
                continue;
            }
            $orderValue = $minimum['basis'] === 'gross'
                ? (float)$order->getGrandTotal()
                : (float)$order->getGrandTotal() - (float)$order->getTaxAmount();
            if (!$this->minimumOrderGate->isBelowMinimum($minimum, $orderValue, $orderCurrency, $storeId)) {
                continue;
            }
            $display = $this->minimumOrderGate->getMinimumForDisplay($minimum, $orderCurrency, $storeId);
            if ($display !== null) {
                throw new LocalizedException($this->minimumOrderMessage($display, $order));
            }
            throw new LocalizedException(
                __('Invoice purchase with %1 is not available for this order.', $this->brandRegistry->getProductName())
            );
        }
    }

    /**
     * Buyer-facing "below minimum order value" message for a display-currency
     * minimum tuple. Shared by the authorize() backstop and the API-decline
     * interpretation so both surface the same wording.
     *
     * @param array{amount: float, basis: string} $displayMinimum
     */
    private function minimumOrderMessage(array $displayMinimum, Order $order): Phrase
    {
        return __(
            'Invoice purchase with %1 is not available for this order. Minimum order value is %2 %3 tax.',
            $this->brandRegistry->getProductName(),
            $order->getOrderCurrency()->formatTxt($displayMinimum['amount']),
            $displayMinimum['basis'] === 'gross' ? __('including') : __('excluding')
        );
    }

    /**
     * Whether this store view runs Amasty OneStepCheckout as a DELIBERATE,
     * admin-set choice — the signal that isAvailable()'s server min gate must
     * be deferred to the client gate + authorize() backstop (see isAvailable()).
     *
     * We cannot simply read amasty_checkout/general/enabled via ScopeConfig:
     * Amasty ships that flag as enabled=1 in config.xml, so on every store view
     * where an admin never touched the setting it reads true by inheritance and
     * the bypass would leak onto Luma / Hyva / Fire checkouts, silently
     * disabling their (working, shipping-aware) server gate. We therefore
     * require BOTH the effective flag to be on AND an explicit core_config_data
     * override enabling it in this store's scope chain — proof an admin
     * configured Amasty, not merely inherited the packaged default. Memoised
     * per store; isAvailable() fires repeatedly per page.
     */
    private function isAmastyCheckoutStore(?\Magento\Store\Api\Data\StoreInterface $store, ?int $storeId): bool
    {
        if ($store === null || $storeId === null) {
            return false;
        }
        if (isset($this->amastyCheckoutStore[$storeId])) {
            return $this->amastyCheckoutStore[$storeId];
        }
        $enabled = $this->_scopeConfig->isSetFlag(
            'amasty_checkout/general/enabled',
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
            $storeId
        ) && $this->hasAmastyConfigOverride($store);
        $this->amastyCheckoutStore[$storeId] = $enabled;
        return $enabled;
    }

    /**
     * Whether an explicit core_config_data row enables Amasty OSC anywhere in
     * this store's scope chain (default, its website, or the store view) — i.e.
     * an admin set the value, as opposed to inheriting Amasty's config.xml
     * packaged default. A store-scoped disable is already reflected by the
     * effective isSetFlag() check in the caller, so any truthy override in the
     * chain proves deliberate intent.
     */
    private function hasAmastyConfigOverride(\Magento\Store\Api\Data\StoreInterface $store): bool
    {
        $collection = $this->configDataCollectionFactory->create();
        $collection->addFieldToFilter('path', 'amasty_checkout/general/enabled');
        foreach ($collection as $row) {
            if (!(bool)$row->getValue()) {
                continue;
            }
            $scope = (string)$row->getScope();
            $scopeId = (int)$row->getScopeId();
            if ($scope === ScopeConfigInterface::SCOPE_TYPE_DEFAULT
                || ($scope === \Magento\Store\Model\ScopeInterface::SCOPE_WEBSITES
                    && $scopeId === (int)$store->getWebsiteId())
                || ($scope === \Magento\Store\Model\ScopeInterface::SCOPE_STORES
                    && $scopeId === (int)$store->getId())
            ) {
                return true;
            }
        }
        return false;
    }
}
