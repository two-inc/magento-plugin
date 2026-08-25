<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Cron;

use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Lock\LockManagerInterface;
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
 *
 * A single order's worst-case latency across the 3 upload steps can
 * exceed the 1-minute schedule (etc/crontab.xml), so nothing here
 * guarantees the previous tick has finished before the next one starts
 * — and Magento's cron scheduler only guarantees single-execution of
 * one *schedule row*, not job-code-level mutual exclusion across ticks
 * or replicas. Each order is claimed via a non-blocking
 * LockManagerInterface lock before upload() runs, so an overlapping
 * tick (or a second cron-eligible pod) skips an order already being
 * worked instead of racing UploadService's read-modify-write on the
 * same row (TWO-24758 review, Han/Yoda/Vader).
 */
class ProcessInvoiceUploads
{
    /** Cap batch size so one cron tick can't run indefinitely. */
    private const BATCH_SIZE = 50;

    private const LOCK_PREFIX = 'two_gateway_invoice_upload_';

    /** @var OrderRepositoryInterface */
    private $orderRepository;

    /** @var SearchCriteriaBuilder */
    private $searchCriteriaBuilder;

    /** @var UploadService */
    private $uploadService;

    /** @var LogRepository */
    private $logRepository;

    /** @var LockManagerInterface */
    private $lockManager;

    public function __construct(
        OrderRepositoryInterface $orderRepository,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        UploadService $uploadService,
        LogRepository $logRepository,
        LockManagerInterface $lockManager
    ) {
        $this->orderRepository = $orderRepository;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->uploadService = $uploadService;
        $this->logRepository = $logRepository;
        $this->lockManager = $lockManager;
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

            $lockName = self::LOCK_PREFIX . $order->getEntityId();
            if (!$this->lockManager->lock($lockName, 0)) {
                // Another tick/replica is already working this order.
                continue;
            }

            try {
                $this->uploadService->upload($order, $twoInvoiceId);
            } catch (Throwable $e) {
                $this->logRepository->addErrorLog(
                    'invoice-upload-cron-exception',
                    ['order_id' => $order->getEntityId(), 'error' => $e->getMessage()]
                );
            } finally {
                $this->lockManager->unlock($lockName);
            }
        }
    }
}
