<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Cron;

use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\Log\RepositoryInterface as LogRepository;
use Two\Gateway\Cron\ProcessInvoiceUploads;
use Two\Gateway\Service\Invoice\UploadService;

class ProcessInvoiceUploadsTest extends TestCase
{
    /** @var OrderRepositoryInterface|\PHPUnit\Framework\MockObject\MockObject */
    private $orderRepository;

    /** @var UploadService|\PHPUnit\Framework\MockObject\MockObject */
    private $uploadService;

    /** @var LogRepository|\PHPUnit\Framework\MockObject\MockObject */
    private $logRepository;

    /** @var ProcessInvoiceUploads */
    private $cron;

    protected function setUp(): void
    {
        $this->orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $this->uploadService = $this->createMock(UploadService::class);
        $this->logRepository = $this->createMock(LogRepository::class);

        $this->cron = new ProcessInvoiceUploads(
            $this->orderRepository,
            new SearchCriteriaBuilder(),
            $this->uploadService,
            $this->logRepository
        );
    }

    private function makeOrder(int $id, string $twoInvoiceId): Order
    {
        $order = new Order();
        $order->setData('entity_id', $id);
        $order->setData('two_invoice_id', $twoInvoiceId);
        return $order;
    }

    public function testProcessesEachOrderReturnedByTheSearch(): void
    {
        $orderA = $this->makeOrder(1, 'inv-a');
        $orderB = $this->makeOrder(2, 'inv-b');
        $this->stubSearchResults([$orderA, $orderB]);

        $this->uploadService->expects($this->exactly(2))->method('upload')
            ->willReturnCallback(function ($order, $twoInvoiceId) use ($orderA, $orderB) {
                static $call = 0;
                $call++;
                if ($call === 1) {
                    $this->assertSame($orderA, $order);
                    $this->assertSame('inv-a', $twoInvoiceId);
                } else {
                    $this->assertSame($orderB, $order);
                    $this->assertSame('inv-b', $twoInvoiceId);
                }
            });

        $this->cron->execute();
    }

    public function testSkipsOrderMissingTwoInvoiceId(): void
    {
        $order = $this->makeOrder(1, '');
        $this->stubSearchResults([$order]);

        $this->uploadService->expects($this->never())->method('upload');

        $this->cron->execute();
    }

    public function testExceptionFromOneOrderDoesNotStopTheBatch(): void
    {
        $orderA = $this->makeOrder(1, 'inv-a');
        $orderB = $this->makeOrder(2, 'inv-b');
        $this->stubSearchResults([$orderA, $orderB]);

        $this->uploadService->expects($this->exactly(2))->method('upload')
            ->willReturnCallback(function ($order) use ($orderA) {
                if ($order === $orderA) {
                    throw new \RuntimeException('boom');
                }
            });
        $this->logRepository->expects($this->once())->method('addErrorLog');

        $this->cron->execute();
    }

    private function stubSearchResults(array $orders): void
    {
        $searchResults = new class ($orders) {
            private $orders;
            public function __construct(array $orders)
            {
                $this->orders = $orders;
            }
            public function getItems(): array
            {
                return $this->orders;
            }
        };
        $this->orderRepository->method('getList')->willReturn($searchResults);
    }
}
