<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Service\Invoice;

use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Api\OrderStatusHistoryRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Pdf\Invoice as InvoicePdf;
use Magento\Sales\Model\Order\Status\HistoryFactory;
use Magento\Sales\Model\Order\Status\History;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\Log\RepositoryInterface as LogRepository;
use Two\Gateway\Service\Api\Adapter;
use Two\Gateway\Service\Invoice\UploadService;
use Two\Gateway\Service\Merchant\SettingsProvider;

class UploadServiceTest extends TestCase
{
    /** @var SettingsProvider|\PHPUnit\Framework\MockObject\MockObject */
    private $settingsProvider;

    /** @var Adapter|\PHPUnit\Framework\MockObject\MockObject */
    private $apiAdapter;

    /** @var InvoicePdf|\PHPUnit\Framework\MockObject\MockObject */
    private $invoicePdf;

    /** @var OrderRepositoryInterface|\PHPUnit\Framework\MockObject\MockObject */
    private $orderRepository;

    /** @var HistoryFactory|\PHPUnit\Framework\MockObject\MockObject */
    private $historyFactory;

    /** @var OrderStatusHistoryRepositoryInterface|\PHPUnit\Framework\MockObject\MockObject */
    private $orderStatusHistoryRepository;

    /** @var CurlFactory|\PHPUnit\Framework\MockObject\MockObject */
    private $curlFactory;

    /** @var LogRepository|\PHPUnit\Framework\MockObject\MockObject */
    private $logRepository;

    /** @var UploadService */
    private $service;

    protected function setUp(): void
    {
        $this->settingsProvider = $this->createMock(SettingsProvider::class);
        $this->apiAdapter = $this->createMock(Adapter::class);
        $this->invoicePdf = $this->createMock(InvoicePdf::class);
        $this->orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $this->historyFactory = $this->createMock(HistoryFactory::class);
        $this->orderStatusHistoryRepository = $this->createMock(OrderStatusHistoryRepositoryInterface::class);
        $this->curlFactory = $this->createMock(CurlFactory::class);
        $this->logRepository = $this->createMock(LogRepository::class);

        $this->historyFactory->method('create')->willReturn(new History());

        $this->service = new UploadService(
            $this->settingsProvider,
            $this->apiAdapter,
            $this->invoicePdf,
            $this->orderRepository,
            $this->historyFactory,
            $this->orderStatusHistoryRepository,
            $this->curlFactory,
            $this->logRepository
        );
    }

    private function makeOrder(array $data = []): Order
    {
        $order = new Order();
        foreach (array_merge(['entity_id' => 42, 'store_id' => 1, 'status' => 'complete'], $data) as $key => $value) {
            $order->setData($key, $value);
        }
        return $order;
    }

    // --- queueForOrder ---

    public function testQueueForOrderMarksNotApplicableWhenFlagFalse(): void
    {
        $order = $this->makeOrder();
        $this->settingsProvider->method('isInvoiceDistributedByMerchant')->with(1)->willReturn(false);
        $this->orderRepository->expects($this->once())->method('save')->with($order);

        $this->service->queueForOrder($order, 'inv-123');

        $this->assertSame(UploadService::STATUS_NOT_APPLICABLE, $order->getData('two_invoice_upload_status'));
    }

    public function testQueueForOrderMarksNotApplicableWhenFlagAbsent(): void
    {
        // SettingsProvider itself degrades an absent/unresolvable record to
        // false; the service just needs to honour whatever it returns.
        $order = $this->makeOrder();
        $this->settingsProvider->method('isInvoiceDistributedByMerchant')->willReturn(false);

        $this->service->queueForOrder($order, 'inv-123');

        $this->assertSame(UploadService::STATUS_NOT_APPLICABLE, $order->getData('two_invoice_upload_status'));
    }

    public function testQueueForOrderQueuesForUploadWhenFlagTrue(): void
    {
        $order = $this->makeOrder();
        $this->settingsProvider->method('isInvoiceDistributedByMerchant')->willReturn(true);
        $this->orderRepository->expects($this->once())->method('save')->with($order);

        $this->service->queueForOrder($order, 'inv-123');

        $this->assertSame(UploadService::STATUS_UPLOADING, $order->getData('two_invoice_upload_status'));
        $this->assertSame('inv-123', $order->getData('two_invoice_id'));
    }

    public function testQueueForOrderMarksNotApplicableWhenInvoiceIdMissing(): void
    {
        $order = $this->makeOrder();
        $this->settingsProvider->method('isInvoiceDistributedByMerchant')->willReturn(true);
        $this->logRepository->expects($this->once())->method('addErrorLog');
        $this->orderRepository->expects($this->once())->method('save');

        $this->service->queueForOrder($order, null);

        $this->assertSame(UploadService::STATUS_NOT_APPLICABLE, $order->getData('two_invoice_upload_status'));
    }

    public function testQueueForOrderIsNoopWhenAlreadyUploaded(): void
    {
        $order = $this->makeOrder(['two_invoice_upload_status' => UploadService::STATUS_UPLOADED]);
        $this->settingsProvider->expects($this->never())->method('isInvoiceDistributedByMerchant');
        $this->orderRepository->expects($this->never())->method('save');

        $this->service->queueForOrder($order, 'inv-123');

        $this->assertSame(UploadService::STATUS_UPLOADED, $order->getData('two_invoice_upload_status'));
    }

    // --- upload() ---

    public function testUploadIsNoopWhenAlreadyUploaded(): void
    {
        $order = $this->makeOrder(['two_invoice_upload_status' => UploadService::STATUS_UPLOADED]);
        $this->apiAdapter->expects($this->never())->method('execute');
        $this->orderRepository->expects($this->never())->method('save');

        $this->service->upload($order, 'inv-123');
    }

    public function testUploadSucceedsThroughAllThreeSteps(): void
    {
        $order = $this->makeOrder();
        $order->setData('invoice_collection', $this->makeInvoiceCollection());

        $this->invoicePdf->method('getPdf')->willReturn($this->makePdfDocument('PDF-BYTES'));

        $curl = $this->createMock(Curl::class);
        $curl->method('getStatus')->willReturn(200);
        $this->curlFactory->method('create')->willReturn($curl);

        $this->apiAdapter->method('execute')->willReturnCallback(function ($endpoint, $payload, $method) {
            if (strpos($endpoint, '/uploads/v1/invoice/') === 0) {
                $this->assertSame('PUT', $method);
                return [
                    'url' => 'https://storage.example/signed',
                    'headers' => ['Content-Type' => 'application/pdf'],
                    'reference' => 'ref-456',
                ];
            }
            $this->assertSame('/uploads/v1/status/ref-456', $endpoint);
            $this->assertSame('GET', $method);
            return ['status' => 'OK'];
        });

        $this->orderRepository->expects($this->once())->method('save')->with($order);
        $this->orderStatusHistoryRepository->expects($this->once())->method('save')
            ->with($this->callback(function (History $history) {
                return strpos((string)$history->getComment(), 'uploaded to Two successfully') !== false;
            }));

        $this->service->upload($order, 'inv-123');

        $this->assertSame(UploadService::STATUS_UPLOADED, $order->getData('two_invoice_upload_status'));
        $this->assertSame('ref-456', $order->getData('two_invoice_upload_reference'));
    }

    public function testUploadFailsWhenNoMagentoInvoiceExists(): void
    {
        $order = $this->makeOrder();
        $order->setData('invoice_collection', $this->makeInvoiceCollection(false));

        $this->orderStatusHistoryRepository->expects($this->once())->method('save');
        $this->logRepository->expects($this->once())->method('addErrorLog');

        $this->service->upload($order, 'inv-123');

        $this->assertSame(UploadService::STATUS_FAILED, $order->getData('two_invoice_upload_status'));
    }

    public function testUploadFailsWhenPdfEmpty(): void
    {
        $order = $this->makeOrder();
        $order->setData('invoice_collection', $this->makeInvoiceCollection());
        $this->invoicePdf->method('getPdf')->willReturn($this->makePdfDocument(''));

        $this->service->upload($order, 'inv-123');

        $this->assertSame(UploadService::STATUS_FAILED, $order->getData('two_invoice_upload_status'));
        $this->assertStringContainsString('empty', (string)$order->getData('two_invoice_upload_error'));
    }

    public function testUploadFailsWhenSignedUrlRequestRejected(): void
    {
        $order = $this->makeOrder();
        $order->setData('invoice_collection', $this->makeInvoiceCollection());
        $this->invoicePdf->method('getPdf')->willReturn($this->makePdfDocument('PDF-BYTES'));

        $this->apiAdapter->method('execute')->willReturn(['http_status' => 403]);

        $this->service->upload($order, 'inv-123');

        $this->assertSame(UploadService::STATUS_FAILED, $order->getData('two_invoice_upload_status'));
        $this->assertStringContainsString(
            'not permitted',
            (string)$order->getData('two_invoice_upload_error')
        );
    }

    public function testUploadRetriesOnServerErrorThenSucceeds(): void
    {
        $order = $this->makeOrder();
        $order->setData('invoice_collection', $this->makeInvoiceCollection());
        $this->invoicePdf->method('getPdf')->willReturn($this->makePdfDocument('PDF-BYTES'));

        $failingCurl = $this->createMock(Curl::class);
        $failingCurl->method('getStatus')->willReturn(500);
        $failingCurl->method('getBody')->willReturn('server error');

        $succeedingCurl = $this->createMock(Curl::class);
        $succeedingCurl->method('getStatus')->willReturn(200);

        $curlSequence = [$failingCurl, $failingCurl, $succeedingCurl];
        $callCount = 0;
        $this->curlFactory->method('create')->willReturnCallback(function () use ($curlSequence, &$callCount) {
            return $curlSequence[$callCount++];
        });

        $this->apiAdapter->method('execute')->willReturnCallback(function ($endpoint) {
            if (strpos($endpoint, '/uploads/v1/invoice/') === 0) {
                return ['url' => 'https://storage.example/signed', 'headers' => [], 'reference' => 'ref-789'];
            }
            return ['status' => 'OK'];
        });

        $this->service->upload($order, 'inv-123');

        $this->assertSame(UploadService::STATUS_UPLOADED, $order->getData('two_invoice_upload_status'));
    }

    public function testUploadDoesNotRetryOnClientError(): void
    {
        $order = $this->makeOrder();
        $order->setData('invoice_collection', $this->makeInvoiceCollection());
        $this->invoicePdf->method('getPdf')->willReturn($this->makePdfDocument('PDF-BYTES'));

        $curl = $this->createMock(Curl::class);
        $curl->method('getStatus')->willReturn(403);
        $curl->method('getBody')->willReturn('forbidden');

        // Exactly one attempt: a 4xx must not be retried.
        $this->curlFactory->expects($this->once())->method('create')->willReturn($curl);

        $this->apiAdapter->method('execute')->willReturn([
            'url' => 'https://storage.example/signed',
            'headers' => [],
            'reference' => 'ref-000',
        ]);

        $this->service->upload($order, 'inv-123');

        $this->assertSame(UploadService::STATUS_FAILED, $order->getData('two_invoice_upload_status'));
    }

    public function testUploadFailsWhenPollingReportsInvalid(): void
    {
        $order = $this->makeOrder();
        $order->setData('invoice_collection', $this->makeInvoiceCollection());
        $this->invoicePdf->method('getPdf')->willReturn($this->makePdfDocument('PDF-BYTES'));

        $curl = $this->createMock(Curl::class);
        $curl->method('getStatus')->willReturn(200);
        $this->curlFactory->method('create')->willReturn($curl);

        $this->apiAdapter->method('execute')->willReturnCallback(function ($endpoint) {
            if (strpos($endpoint, '/uploads/v1/invoice/') === 0) {
                return ['url' => 'https://storage.example/signed', 'headers' => [], 'reference' => 'ref-inv'];
            }
            return ['status' => 'INVALID'];
        });

        $this->service->upload($order, 'inv-123');

        $this->assertSame(UploadService::STATUS_FAILED, $order->getData('two_invoice_upload_status'));
        $this->assertStringContainsString(
            'validation failed',
            (string)$order->getData('two_invoice_upload_error')
        );
    }

    private function makeInvoiceCollection(bool $hasInvoice = true)
    {
        $invoice = new class ($hasInvoice) {
            private $hasInvoice;
            public function __construct(bool $hasInvoice)
            {
                $this->hasInvoice = $hasInvoice;
            }
            public function getEntityId()
            {
                return $this->hasInvoice ? 99 : null;
            }
        };

        return new class ($hasInvoice ? $invoice : null) {
            private $invoice;
            public function __construct($invoice)
            {
                $this->invoice = $invoice;
            }
            public function getFirstItem()
            {
                return $this->invoice;
            }
        };
    }

    private function makePdfDocument(string $content)
    {
        return new class ($content) {
            private $content;
            public function __construct(string $content)
            {
                $this->content = $content;
            }
            public function render(): string
            {
                return $this->content;
            }
        };
    }
}
