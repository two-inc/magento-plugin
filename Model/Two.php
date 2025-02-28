<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace ABN\Gateway\Model;

use Exception;
use Magento\Framework\Api\AttributeValueFactory;
use Magento\Framework\Api\ExtensionAttributesFactory;
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
use ABN\Gateway\Api\Config\RepositoryInterface as ConfigRepository;
use ABN\Gateway\Service\Api\Adapter;
use ABN\Gateway\Service\Order\ComposeCapture;
use ABN\Gateway\Service\Order\ComposeOrder;
use ABN\Gateway\Service\Order\ComposeRefund;
use ABN\Gateway\Service\UrlCookie;
use ABN\Gateway\Api\Log\RepositoryInterface as LogRepository;

/**
 * Two Payment Model
 */
class Two extends AbstractMethod
{
    public const CODE = 'abn_payment';

    public const STATUS_NEW = 'abn_new';
    public const STATUS_FAILED = 'abn_failed';
    public const STATUS_PENDING = 'pending_abn_payment';
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
        AbstractResource $resource = null,
        AbstractDb $resourceCollection = null,
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
        $this->urlCookie = $urlCookie;
        $this->compositeOrder = $composeOrder;
        $this->composeRefund = $composeRefund;
        $this->composeCapture = $composeCapture;
        $this->apiAdapter = $apiAdapter;
        $this->historyFactory = $historyFactory;
        $this->orderStatusHistoryRepository = $orderStatusHistoryRepository;
        $this->orderRepository = $orderRepository;
        $this->logRepository = $logRepository;
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
                sprintf('Order was not accepted by %s', $this->configRepository::PRODUCT_NAME),
                $response
            );
            throw new LocalizedException(
                __('Invoice purchase with %1 is not available for this order.', $this->configRepository::PRODUCT_NAME)
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
        $this->urlCookie->set($response['payment_url']);
        return $this;
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
            $this->configRepository::PRODUCT_NAME,
            $tryAgainLater
        );
        if (!$response || !is_array($response)) {
            return $generalError;
        }

        $traceID = null;
        if (array_key_exists('error_trace_id', $response)) {
            $traceID = $response['error_trace_id'];
        }

        // Validation errors
        if (isset($response['error_json']) && is_array($response['error_json'])) {
            $errs = [];
            foreach ($response['error_json'] as $err) {
                if ($err && $err['loc']) {
                    $err_field = $this->getFieldFromLocStr(json_encode($err['loc']));
                    if ($err_field) {
                        array_push($errs, $err_field);
                    } else {
                        // Since err_field is empty, return general error message
                        return $this->_getMessageWithTrace($generalError, $traceID);
                    }
                }
            }
            if (count($errs) > 0) {
                $message = __(
                    'Your request to %1 failed. Reason: %2',
                    $this->configRepository::PRODUCT_NAME,
                    join(' ', $errs)
                );
                return $this->_getMessageWithTrace($message, $traceID);
            }
        }

        if (isset($response['error_code'])) {
            // Custom errors
            $reason = $response['error_message'];
            if ($response['error_code'] == 'SAME_BUYER_SELLER_ERROR') {
                $reason = __('The buyer and the seller are the same company.');
            }
            $message = __(
                'Your request to %1 failed. Reason: %2',
                $this->configRepository::PRODUCT_NAME,
                $reason
            );
            return $this->_getMessageWithTrace($message, $traceID);
        }

        return null;
    }

    /**
     * Get validation message
     *
     * @param $loc_str
     * @return string|null
     */
    public function getFieldFromLocStr($loc_str): ?Phrase
    {
        $loc_str = preg_replace('/\s+/', '', $loc_str);
        $fieldLocStrMapping = [
            '["buyer","representative","phone_number"]' => __('Phone Number is not valid.'),
            '["buyer","company","organization_number"]' => __('KVK number is not valid.'),
            '["buyer","representative","first_name"]' => __('First Name is not valid.'),
            '["buyer","representative","last_name"]' => __('Last Name is not valid.'),
            '["buyer","representative","email"]' => __('Email Address is not valid.'),
            '["billing_address","street_address"]' => __('Street Address is not valid.'),
            '["billing_address","city"]' => __('City is not valid.'),
            '["billing_address","country"]' => __('Country is not valid.'),
            '["billing_address","postal_code"]' => __('Zip/Postal Code is not valid.'),
        ];
        if (array_key_exists($loc_str, $fieldLocStrMapping)) {
            return $fieldLocStrMapping[$loc_str];
        }
        return null;
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
                    $this->configRepository::PRODUCT_NAME,
                    $twoOrderId,
                    $error
                );
                $order->addStatusToHistory($order->getStatus(), $comment->render());
            } else {
                $order->addStatusToHistory(
                    $order->getStatus(),
                    __('%1 order has been marked as cancelled', $this->configRepository::PRODUCT_NAME)
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
                    __('Could not initiate capture with %1', $this->configRepository::PRODUCT_NAME)
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
                $this->configRepository::PRODUCT_NAME,
            );
        } else {
            $comment = __(
                '%1 order marked as partially completed.',
                $this->configRepository::PRODUCT_NAME,
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
                __('Could not initiate refund with %1', $this->configRepository::PRODUCT_NAME),
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
                $this->configRepository::PRODUCT_NAME,
                $reason
            );
            $this->addOrderComment($order, $message);
            throw new LocalizedException(
                $message
            );
        }

        $comment = __(
            'Successfully refunded order with %1 for order ID: %2. Refund reference: %3',
            $this->configRepository::PRODUCT_NAME,
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
     */
    public function isAvailable(CartInterface $quote = null)
    {
        if (!$this->configRepository->isActive()
            || $this->configRepository->getApiKey() == '') {
            return false;
        }

        return parent::isAvailable($quote);
    }
}
