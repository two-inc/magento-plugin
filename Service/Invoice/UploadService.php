<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Service\Invoice;

use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Pdf\Invoice as InvoicePdf;
use Magento\Sales\Model\Order\Status\HistoryFactory;
use Magento\Sales\Api\OrderStatusHistoryRepositoryInterface;
use Throwable;
use Two\Gateway\Api\Log\RepositoryInterface as LogRepository;
use Two\Gateway\Service\Api\Adapter;
use Two\Gateway\Service\Merchant\SettingsProvider;

/**
 * Uploads a merchant-generated invoice PDF to Two, mirroring the shipped
 * PrestaShop pattern (TwoInvoiceUploadService.php) via checkout-api's
 * 3-step signed-URL flow:
 *
 *   1. PUT /uploads/v1/invoice/{invoice_id}/external_invoice/{index}
 *      -> signed GCS URL + reference
 *   2. PUT {signed_url} with the raw PDF bytes -> GCS
 *   3. GET /uploads/v1/status/{reference} -> poll until resolved
 *
 * Gated solely on invoice_distributed_by_merchant from GET /v1/merchant
 * (TWO-25106, Option A — no admin toggle). Renders the invoice with
 * Magento's native Magento\Sales\Model\Order\Pdf\Invoice.
 *
 * Split into two phases so the network-bound work never runs inline in
 * the fulfilment observer (PHP max_execution_time risk):
 *  - queueForOrder(): gate check + cheap DB write only, called from the
 *    observer synchronously.
 *  - upload(): the actual render + 3-step upload, called from the
 *    ProcessInvoiceUploads cron for orders left in UPLOADING.
 */
class UploadService
{
    public const STATUS_NOT_APPLICABLE = 'NOT_APPLICABLE';
    public const STATUS_UPLOADING = 'UPLOADING';
    public const STATUS_UPLOADED = 'UPLOADED';
    public const STATUS_FAILED = 'FAILED';

    private const MAX_FILE_SIZE = 2097152;
    private const POLLING_TIMEOUT = 60;
    private const POLLING_INTERVAL = 1;
    private const MAX_RETRIES = 3;
    private const UPLOAD_INDEX = 0;

    /** @var SettingsProvider */
    private $settingsProvider;

    /** @var Adapter */
    private $apiAdapter;

    /** @var InvoicePdf */
    private $invoicePdf;

    /** @var OrderRepositoryInterface */
    private $orderRepository;

    /** @var HistoryFactory */
    private $historyFactory;

    /** @var OrderStatusHistoryRepositoryInterface */
    private $orderStatusHistoryRepository;

    /** @var CurlFactory */
    private $curlFactory;

    /** @var LogRepository */
    private $logRepository;

    public function __construct(
        SettingsProvider $settingsProvider,
        Adapter $apiAdapter,
        InvoicePdf $invoicePdf,
        OrderRepositoryInterface $orderRepository,
        HistoryFactory $historyFactory,
        OrderStatusHistoryRepositoryInterface $orderStatusHistoryRepository,
        CurlFactory $curlFactory,
        LogRepository $logRepository
    ) {
        $this->settingsProvider = $settingsProvider;
        $this->apiAdapter = $apiAdapter;
        $this->invoicePdf = $invoicePdf;
        $this->orderRepository = $orderRepository;
        $this->historyFactory = $historyFactory;
        $this->orderStatusHistoryRepository = $orderStatusHistoryRepository;
        $this->curlFactory = $curlFactory;
        $this->logRepository = $logRepository;
    }

    /**
     * Called synchronously from the fulfilment observer. Cheap only:
     * a gate check and a status write. The actual render + upload is
     * left for the cron (upload()) so the observer never blocks on
     * network I/O.
     *
     * @param OrderInterface|Order $order
     * @param string|null $twoInvoiceId Two's invoice id from the
     *        fulfilments response (null if not present/resolvable)
     */
    public function queueForOrder($order, ?string $twoInvoiceId): void
    {
        $currentStatus = (string)$order->getData('two_invoice_upload_status');

        // Duplicate guard: never re-queue a terminal UPLOADED order, and
        // never re-queue an order already UPLOADING — Magento is known to
        // occasionally dispatch sales_order_shipment_save_after more than
        // once for the same shipment, and a second call resetting
        // two_invoice_upload_reference/error here would race the cron's
        // upload() if it's already mid-flight for this order (TWO-24758
        // review, Han/Vader).
        if ($currentStatus === self::STATUS_UPLOADED || $currentStatus === self::STATUS_UPLOADING) {
            return;
        }

        if (!$this->settingsProvider->isInvoiceDistributedByMerchant((int)$order->getStoreId())) {
            $this->persistStatus($order, self::STATUS_NOT_APPLICABLE);
            $this->orderRepository->save($order);
            return;
        }

        if ($twoInvoiceId === null || $twoInvoiceId === '') {
            $this->logRepository->addErrorLog(
                'invoice-upload-queue',
                [
                    'order_id' => $order->getEntityId(),
                    'message' => 'Two invoice id missing on fulfilment response; cannot queue upload',
                ]
            );
            $this->persistStatus($order, self::STATUS_NOT_APPLICABLE);
            $this->orderRepository->save($order);
            return;
        }

        $order->setData('two_invoice_id', $twoInvoiceId);
        $order->setData('two_invoice_upload_reference', null);
        $order->setData('two_invoice_upload_error', null);
        $this->persistStatus($order, self::STATUS_UPLOADING);
        $this->orderRepository->save($order);
    }

    /**
     * Called from the ProcessInvoiceUploads cron for orders left in
     * UPLOADING. Renders the Magento invoice PDF natively and runs the
     * 3-step upload, persisting one of UPLOADED / FAILED on completion
     * and adding an order history comment.
     *
     * @param OrderInterface|Order $order
     * @param string $twoInvoiceId
     */
    public function upload($order, string $twoInvoiceId): void
    {
        $orderId = (int)$order->getEntityId();
        $storeId = (int)$order->getStoreId();

        if ((string)$order->getData('two_invoice_upload_status') === self::STATUS_UPLOADED) {
            // Already completed by a previous run; nothing to do.
            return;
        }

        // Re-check the gate at execution time, not just at queue time: the
        // cron can run minutes after queueForOrder(), and the merchant may
        // have flipped invoice_distributed_by_merchant to false in between
        // (TWO-24758 review, Vader). A flip the other way (false -> true)
        // is not retro-actively picked up for orders already resolved to
        // NOT_APPLICABLE; that is an accepted limitation, not a bug fixed
        // here.
        if (!$this->settingsProvider->isInvoiceDistributedByMerchant($storeId)) {
            $this->persistStatus($order, self::STATUS_NOT_APPLICABLE);
            $order->setData('two_invoice_upload_error', null);
            $this->orderRepository->save($order);
            return;
        }

        try {
            $pdfContent = $this->renderInvoicePdf($order);
        } catch (NoInvoiceException $e) {
            // Legitimately nothing to upload (e.g. a zero-grand-total
            // order never gets a Magento invoice) — not a failure.
            $this->persistStatus($order, self::STATUS_NOT_APPLICABLE);
            $order->setData('two_invoice_upload_error', null);
            $this->orderRepository->save($order);
            $this->logRepository->addDebugLog(
                'invoice-upload-not-applicable',
                ['order_id' => $orderId, 'reason' => $e->getMessage()]
            );
            return;
        } catch (Throwable $e) {
            $this->fail($order, 'Failed to render invoice PDF: ' . $e->getMessage());
            return;
        }

        $pdfSize = strlen($pdfContent);
        if ($pdfSize === 0) {
            $this->fail($order, 'Invoice PDF is empty');
            return;
        }
        if ($pdfSize > self::MAX_FILE_SIZE) {
            $this->fail(
                $order,
                'Invoice PDF exceeds maximum size (2MB). Size: ' . round($pdfSize / 1024 / 1024, 2) . 'MB'
            );
            return;
        }

        $signedUrl = $this->requestSignedUploadUrl($twoInvoiceId, $storeId);
        if (!$signedUrl['success']) {
            $this->fail($order, $signedUrl['error']);
            return;
        }

        $uploadResult = $this->uploadToCloudStorage($signedUrl['url'], $signedUrl['headers'], $pdfContent);
        if (!$uploadResult['success']) {
            $this->fail($order, $uploadResult['error']);
            return;
        }

        $order->setData('two_invoice_upload_reference', $signedUrl['reference']);

        $statusResult = $this->pollUploadStatus($signedUrl['reference'], $orderId, $storeId);
        if (!$statusResult['success']) {
            $this->fail($order, $statusResult['error']);
            return;
        }

        $this->succeed($order);
    }

    /**
     * @return string Raw PDF bytes
     * @throws NoInvoiceException When the order has no Magento invoice yet
     *         (a legitimate NOT_APPLICABLE case, e.g. a zero-grand-total
     *         order that never gets one) — distinct from a genuine render
     *         failure so the caller doesn't mark it FAILED.
     */
    private function renderInvoicePdf($order): string
    {
        $invoices = $order->getInvoiceCollection();
        // Most-recently-created invoice, not blindly the first: an order
        // can already carry an earlier (e.g. partial/admin-created)
        // invoice, and the one from this fulfilment is what should be
        // uploaded (TWO-24758 review, Vader).
        $invoice = $invoices !== null ? $invoices->getLastItem() : null;
        if ($invoice === null || !$invoice->getEntityId()) {
            throw new NoInvoiceException('No Magento invoice found for order');
        }

        $pdf = $this->invoicePdf->getPdf([$invoice]);
        return (string)$pdf->render();
    }

    /**
     * Step 1: request signed upload URL from checkout-api.
     *
     * @return array{success:bool,url?:string,headers?:array,reference?:string,error?:string}
     */
    private function requestSignedUploadUrl(string $twoInvoiceId, int $storeId): array
    {
        $endpoint = '/uploads/v1/invoice/' . rawurlencode($twoInvoiceId) . '/external_invoice/' . self::UPLOAD_INDEX;
        $response = $this->apiAdapter->execute(
            $endpoint,
            ['content_type' => 'application/pdf'],
            'PUT',
            $storeId
        );

        // Adapter::execute() only ever injects http_status on its
        // non-2xx branch; a bare presence check (rather than pinning to
        // one literal success code) matches the >=400 idiom already used
        // elsewhere in this codebase (Service/Order/SurchargeCalculator.php)
        // and tolerates an endpoint that might echo http_status as benign
        // response data on success (TWO-24758 review, Yoda).
        $httpStatus = isset($response['http_status']) ? (int)$response['http_status'] : 0;
        if ($httpStatus >= 400) {
            return ['success' => false, 'error' => $this->parseSignedUrlError($response, $httpStatus)];
        }

        if (!isset($response['url'], $response['headers'], $response['reference'])) {
            return ['success' => false, 'error' => 'Invalid response from Two API (missing url/headers/reference)'];
        }

        return [
            'success' => true,
            'url' => $response['url'],
            'headers' => $response['headers'],
            'reference' => $response['reference'],
        ];
    }

    private function parseSignedUrlError(array $response, int $httpStatus): string
    {
        switch ($httpStatus) {
            case 403:
                return 'Merchant not permitted for invoice uploads (invoice_distributed_by_merchant is false server-side)';
            case 404:
                return 'Invoice not found. Order may not be fulfilled yet.';
            case 409:
                return 'Invoice already uploaded for this index.';
            case 422:
                return 'Validation error: invalid content type (must be application/pdf)';
            default:
                if (isset($response['error_message'])) {
                    return (string)$response['error_message'];
                }
                return 'Failed to request upload URL (HTTP ' . $httpStatus . ')';
        }
    }

    /**
     * Step 2: PUT the PDF bytes to the signed GCS URL. Retries on
     * network errors / 5xx (up to MAX_RETRIES); never retries on 4xx.
     *
     * @return array{success:bool,error?:string}
     */
    private function uploadToCloudStorage(string $url, array $headers, string $pdfContent): array
    {
        $lastError = 'Unknown error';

        for ($attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++) {
            $curl = $this->curlFactory->create();
            foreach ($headers as $name => $value) {
                $curl->addHeader((string)$name, (string)$value);
            }
            $curl->setOption(CURLOPT_CUSTOMREQUEST, 'PUT');
            $curl->setOption(CURLOPT_SSL_VERIFYPEER, true);
            $curl->setOption(CURLOPT_FOLLOWLOCATION, false);
            $curl->setOption(CURLOPT_TIMEOUT, 30);

            try {
                $curl->addHeader('Content-Length', (string)strlen($pdfContent));
                $curl->post($url, $pdfContent);
                $httpCode = (int)$curl->getStatus();
            } catch (Throwable $e) {
                $lastError = 'Network error: ' . $e->getMessage();
                if ($attempt < self::MAX_RETRIES) {
                    continue;
                }
                break;
            }

            if ($httpCode === 200) {
                return ['success' => true];
            }

            $lastError = 'HTTP ' . $httpCode . ': ' . substr((string)$curl->getBody(), 0, 200);

            // 4xx: don't retry, these won't succeed.
            if ($httpCode >= 400 && $httpCode < 500) {
                break;
            }
        }

        return [
            'success' => false,
            'error' => 'Failed to upload to cloud storage after ' . self::MAX_RETRIES . ' attempt(s). '
                . 'Last error: ' . $lastError,
        ];
    }

    /**
     * Step 3: poll GET /uploads/v1/status/{reference} until resolved.
     *
     * @return array{success:bool,error?:string}
     */
    private function pollUploadStatus(string $reference, int $orderId, int $storeId): array
    {
        $startTime = time();

        while ((time() - $startTime) < self::POLLING_TIMEOUT) {
            $response = $this->apiAdapter->execute(
                '/uploads/v1/status/' . rawurlencode($reference),
                [],
                'GET',
                $storeId
            );
            $httpStatus = isset($response['http_status']) ? (int)$response['http_status'] : 0;

            if ($httpStatus >= 400) {
                return ['success' => false, 'error' => 'Failed to poll upload status (HTTP ' . $httpStatus . ')'];
            }

            $status = isset($response['status']) ? strtoupper((string)$response['status']) : '';

            switch ($status) {
                case 'OK':
                    return ['success' => true];
                case 'INVALID':
                    return ['success' => false, 'error' => 'Upload validation failed. PDF may be corrupted or invalid.'];
                case 'PENDING':
                case 'PROCESSING':
                case 'AWAITING_UPLOAD':
                    sleep(self::POLLING_INTERVAL);
                    break;
                default:
                    return ['success' => false, 'error' => 'Unknown status: ' . $status];
            }
        }

        return [
            'success' => false,
            'error' => 'Upload status polling timeout after ' . self::POLLING_TIMEOUT . ' seconds.'
                . ' Upload may still complete in background.',
        ];
    }

    private function succeed($order): void
    {
        $this->persistStatus($order, self::STATUS_UPLOADED);
        $order->setData('two_invoice_uploaded_at', date('Y-m-d H:i:s'));
        $order->setData('two_invoice_upload_error', null);
        $this->orderRepository->save($order);
        $this->addHistoryComment($order, __('Invoice uploaded to Two successfully.'));
        $this->logRepository->addDebugLog(
            'invoice-upload-complete',
            ['order_id' => $order->getEntityId()]
        );
    }

    private function fail($order, string $error): void
    {
        $this->persistStatus($order, self::STATUS_FAILED);
        $order->setData('two_invoice_upload_error', $error);
        $this->orderRepository->save($order);
        $this->addHistoryComment($order, __('Invoice upload failed: %1', $error));
        $this->logRepository->addErrorLog(
            'invoice-upload-failed',
            ['order_id' => $order->getEntityId(), 'error' => $error]
        );
    }

    private function persistStatus($order, string $status): void
    {
        $order->setData('two_invoice_upload_status', $status);
    }

    private function addHistoryComment($order, $comment): void
    {
        $history = $this->historyFactory->create();
        $history->setParentId($order->getEntityId())
            ->setComment((string)$comment)
            ->setEntityName('order')
            ->setStatus($order->getStatus());
        $this->orderStatusHistoryRepository->save($history);
    }
}
