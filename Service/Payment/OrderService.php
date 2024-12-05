<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Service\Payment;

use Exception;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\DB\Transaction;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Url\DecoderInterface;
use Magento\Sales\Api\OrderPaymentRepositoryInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\Order\Payment\Transaction\BuilderInterface as TransactionBuilder;
use Magento\Sales\Model\Order\Payment\Transaction\Repository as PaymentTransactionRepository;
use Magento\Sales\Model\OrderFactory;
use Magento\Sales\Model\ResourceModel\Order as OrderResource;
use Magento\Sales\Model\Service\InvoiceService;
use Two\Gateway\Api\Config\RepositoryInterface as ConfigRepository;
use Two\Gateway\Model\Two;
use Two\Gateway\Service\Api\Adapter;
use Two\Gateway\Service\UrlCookie;
use Two\Gateway\Api\Log\RepositoryInterface as LogRepository;

/**
 * Payment Order Service
 */
class OrderService
{
    /**
     * @var Adapter
     */
    private $apiAdapter;

    /**
     * @var RestoreQuote
     */
    private $restoreQuote;

    /**
     * @var OrderResource
     */
    private $orderResource;

    /**
     * @var OrderFactory
     */
    private $orderFactory;

    /**
     * @var UrlCookie
     */
    private $urlCookie;

    /**
     * @var InvoiceService
     */
    private $invoiceService;

    /**
     * @var Transaction
     */
    private $transaction;
    /**
     * @var DecoderInterface
     */
    private $urlDecoder;
    /**
     * @var ConfigRepository
     */
    private $configRepository;
    /**
     * @var RequestInterface
     */
    private $request;
    /**
     * @var TransactionBuilder
     */
    private $transactionBuilder;
    /**
     * @var PaymentTransactionRepository
     */
    private $paymentTransactionRepository;
    /**
     * @var OrderPaymentRepositoryInterface
     */
    private $orderPaymentRepository;
    /**
     * @var OrderRepositoryInterface
     */
    private $orderRepository;
    /**
     * @var LogRepository
     */
    private $logRepository;

    /**
     * OrderService constructor.
     * @param Adapter $apiAdapter
     * @param RestoreQuote $restoreQuote
     * @param OrderResource $orderResource
     * @param OrderFactory $orderFactory
     * @param UrlCookie $urlCookie
     * @param InvoiceService $invoiceService
     * @param Transaction $transaction
     * @param DecoderInterface $urlDecoder
     * @param ConfigRepository $configRepository
     * @param RequestInterface $request
     * @param TransactionBuilder $transactionBuilder
     * @param PaymentTransactionRepository $paymentTransactionRepository
     * @param OrderPaymentRepositoryInterface $orderPaymentRepository
     * @param OrderRepositoryInterface $orderRepository
     * @param LogRepository $logRepository
     */
    public function __construct(
        Adapter $apiAdapter,
        RestoreQuote $restoreQuote,
        OrderResource $orderResource,
        OrderFactory $orderFactory,
        UrlCookie $urlCookie,
        InvoiceService $invoiceService,
        Transaction $transaction,
        DecoderInterface $urlDecoder,
        ConfigRepository $configRepository,
        RequestInterface $request,
        TransactionBuilder $transactionBuilder,
        PaymentTransactionRepository $paymentTransactionRepository,
        OrderPaymentRepositoryInterface $orderPaymentRepository,
        OrderRepositoryInterface $orderRepository,
        LogRepository $logRepository
    ) {
        $this->apiAdapter = $apiAdapter;
        $this->restoreQuote = $restoreQuote;
        $this->orderResource = $orderResource;
        $this->orderFactory = $orderFactory;
        $this->urlCookie = $urlCookie;
        $this->invoiceService = $invoiceService;
        $this->transaction = $transaction;
        $this->urlDecoder = $urlDecoder;
        $this->configRepository = $configRepository;
        $this->request = $request;
        $this->transactionBuilder = $transactionBuilder;
        $this->paymentTransactionRepository = $paymentTransactionRepository;
        $this->orderRepository = $orderRepository;
        $this->orderPaymentRepository = $orderPaymentRepository;
        $this->logRepository = $logRepository;
    }

    /**
     * Get Two Order by Reference
     *
     * @return Order
     */
    public function getOrderByReference()
    {
        $generalErrorMessage = __('Unable to find the requested %1 order', $this->configRepository::PROVIDER);
        $this->urlCookie->delete();
        if (!$this->getOrderReference()) {
            throw new LocalizedException($generalErrorMessage);
        }

        $order = $this->orderFactory->create();
        $this->orderResource->load(
            $order,
            $this->getOrderReference(),
            'two_order_reference'
        );

        if (!$order->getId() || $order->getPayment()->getMethod() !== Two::CODE || !$order->getTwoOrderId()) {
            throw new LocalizedException($generalErrorMessage);
        }

        return $order;
    }

    /**
     * Get order reference from param
     *
     * @return string|null
     */
    public function getOrderReference(): ?string
    {
        $reference = $this->request->getParam('_two_order_reference', null);
        return $reference ? $this->urlDecoder->decode($reference) : $reference;
    }

    /**
     * Get two order from api
     *
     * @param Order $order
     * @return array
     * @throws LocalizedException
     */
    public function getTwoOrderFromApi(Order $order): array
    {
        $response = $this->apiAdapter->execute('/v1/order/' . $order->getTwoOrderId(), [], 'GET');
        $error = $order->getPayment()->getMethodInstance()->getErrorFromResponse($response);
        if ($error) {
            throw new LocalizedException($error);
        }

        return $response;
    }

    /**
     * Send order confirmation request
     *
     * @param Order $order
     * @return mixed
     * @throws LocalizedException
     */
    public function confirmOrder(Order $order)
    {
        $response = $this->apiAdapter->execute("/v1/order/" . $order->getTwoOrderId() . "/confirm");
        $error = $order->getPayment()->getMethodInstance()->getErrorFromResponse($response);
        if ($error) {
            throw new LocalizedException($error);
        }

        return $response;
    }

    /**
     * Send cancel request to api
     *
     * @param Order $order
     * @return bool
     * @throws LocalizedException
     */
    public function cancelTwoOrder(Order $order): bool
    {
        $response = $this->apiAdapter->execute('/v1/order/' . $order->getTwoOrderId() . '/cancel');
        if ($response) {
            $error = $order->getPayment()->getMethodInstance()->getErrorFromResponse($response);
            if ($error) {
                throw new LocalizedException($error);
            }
        }

        return true;
    }

    /**
     * Restore quote
     *
     * @return $this
     */
    public function restoreQuote()
    {
        $this->restoreQuote->execute();
        return $this;
    }

    /**
     * Fail Two order
     *
     * @param Order $order
     * @param $reason
     * @return $this
     * @throws Exception
     */
    public function failOrder(Order $order, $reason)
    {
        $order->setStatus(Two::STATUS_FAILED);
        $order->setState(Order::STATE_CANCELED);
        $this->addOrderComment($order, $reason);
        $order->getPayment()->save();
        $this->orderRepository->save($order);
        return $this;
    }

    /**
     * Add order comment
     *
     * @param Order $order
     * @param $message
     * @return $this
     */
    public function addOrderComment(Order $order, $message)
    {
        $order->addStatusToHistory($order->getStatus(), $message);
        return $this;
    }

    /**
     * Process Order
     *
     * @param Order $order
     * @return $this
     * @throws LocalizedException
     */
    public function processOrder(Order $order, string $transactionId)
    {
        $payment = $order->getPayment();
        $fulfillTrigger = $this->configRepository->getFulfillTrigger();
        if ($fulfillTrigger == 'shipment' || $fulfillTrigger == 'complete') {
            $order->setIsInProcess(true);
            $order->setState(Order::STATE_PROCESSING);
            $order->setStatus(Order::STATE_PROCESSING);
            $invoice = $this->invoiceService->prepareInvoice($order);
            if ($invoice->getGrandTotal() > 0) {
                $invoice->setRequestedCaptureCase(Invoice::CAPTURE_ONLINE);
                $invoice->register();
                $this->addOrderComment(
                    $order,
                    sprintf(
                        '%s order payment has been verified',
                        $this->configRepository::PROVIDER
                    )
                );
                $transactionSave = $this->transaction
                    ->addObject(
                        $payment
                    )->addObject(
                        $invoice
                    )->addObject(
                        $invoice->getOrder()
                    );
                $transactionSave->save();
                $this->addAuthorizationTransaction($order, $transactionId);
            }
        }
        return $this;
    }

    /**
     * @param int $orderId
     * @param string $transactionId
     */
    private function addAuthorizationTransaction(Order $order, string $transactionId)
    {
        try {
            $transactionId .= '-auth';
            $payment = $order->getPayment();
            $payment->setLastTransId($transactionId);

            $transaction = $this->transactionBuilder->setPayment($payment)
                ->setOrder($order)
                ->setTransactionId($transactionId)
                ->setFailSafe(true)
                ->build(\Magento\Sales\Model\Order\Payment\Transaction::TYPE_AUTH);

            $this->orderPaymentRepository->save($payment);
            $this->orderRepository->save($order);
            $this->paymentTransactionRepository->save($transaction);
        } catch (\Exception $e) {
            $this->logRepository->addDebugLog('Auth transaction', $e->getMessage());
        }
    }
}
