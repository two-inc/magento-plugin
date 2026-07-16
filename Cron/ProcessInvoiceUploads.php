<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Cron;

use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Sales\Api\OrderRepositoryInterface;
use Throwable;
use Two\Gateway\Api\Log\RepositoryInterface as LogRepository;
use Two\Gateway\Service\Invoice\UploadService;

/**
 * Runs the network-bound part of the self-invoice upload flow
 * (render + 3-step upload) for orders the fulfilment observer left in
 * UPLOADING, so that work never runs inline in the HTTP request that
 * handles the shipment (see UploadService::queueForOrder /
 * SalesOrderShipmentAfter).
 */
class ProcessInvoiceUploads
{
    /** Cap batch size so one cron tick can't run indefinitely. */
    private const BATCH_SIZE = 50;

    /** @var OrderRepositoryInterface */
    private $orderRepository;

    /** @var SearchCriteriaBuilder */
    private $searchCriteriaBuilder;

    /** @var UploadService */
    private $uploadService;

    /** @var LogRepository */
    private $logRepository;

    public function __construct(
        OrderRepositoryInterface $orderRepository,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        UploadService $uploadService,
        LogRepository $logRepository
    ) {
        $this->orderRepository = $orderRepository;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->uploadService = $uploadService;
        $this->logRepository = $logRepository;
    }

    public function execute(): void
    {
        $searchCriteria = $this->searchCriteriaBuilder
            ->addFilter('two_invoice_upload_status', UploadService::STATUS_UPLOADING)
            ->setPageSize(self::BATCH_SIZE)
            ->create();

        $orders = $this->orderRepository->getList($searchCriteria)->getItems();

        foreach ($orders as $order) {
            $twoInvoiceId = (string)$order->getData('two_invoice_id');
            if ($twoInvoiceId === '') {
                continue;
            }
            try {
                $this->uploadService->upload($order, $twoInvoiceId);
            } catch (Throwable $e) {
                $this->logRepository->addErrorLog(
                    'invoice-upload-cron-exception',
                    ['order_id' => $order->getEntityId(), 'error' => $e->getMessage()]
                );
            }
        }
    }
}
