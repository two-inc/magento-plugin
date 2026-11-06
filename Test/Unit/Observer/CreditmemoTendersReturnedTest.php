<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Observer;

use Magento\Framework\DataObject;
use Magento\Framework\Event\Observer;
use Magento\Sales\Api\OrderStatusHistoryRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Creditmemo;
use Magento\Sales\Model\Order\Status\HistoryFactory;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\BrandOverlayRegistryInterface;
use Two\Gateway\Api\BrandRegistryInterface;
use Two\Gateway\Api\Log\RepositoryInterface as LogRepository;
use Two\Gateway\Observer\CreditmemoTendersReturned;
use Two\Gateway\Service\Order\ComposeRefund;

/**
 * A credit memo that returns tenders a fulfilled part already declared to
 * Two as prepaid is logged and noted on the order with the amount Two will
 * not collect (TWO-26309).
 */
class CreditmemoTendersReturnedTest extends TestCase
{
    /**
     * @dataProvider memos
     * @param float $memoTenders what the memo returned to the tenders
     * @param int|\Throwable $uncollected uncollectedPrepaid(), in cents, or what it throws
     * @param string|null $logged the error logged, if any
     * @param string|null $comment a fragment of the order comment, if any
     */
    public function testTheMerchantIsToldWhatTwoWillNotCollect(
        float $memoTenders,
        $uncollected,
        ?string $logged,
        ?string $comment,
        string $description
    ): void {
        $order = new Order();
        foreach (['entity_id' => 7, 'increment_id' => '000000042', 'two_order_id' => 'two-1',
            'order_currency_code' => 'EUR', 'status' => 'processing'] as $key => $value) {
            $order->setData($key, $value);
        }
        $order->setPayment(new DataObject(['method' => 'two_payment']));
        $creditmemo = new Creditmemo();
        $creditmemo->setOrder($order);

        $service = $this->createMock(ComposeRefund::class);
        $service->method('getTenderTotal')->willReturn($memoTenders);
        if ($uncollected instanceof \Throwable) {
            $service->method('uncollectedPrepaid')->willThrowException($uncollected);
        } else {
            $service->method('uncollectedPrepaid')->willReturn($uncollected);
        }
        $overlays = $this->createMock(BrandOverlayRegistryInterface::class);
        $overlays->method('isTwoStackMethod')->willReturn(true);
        $brand = $this->createMock(BrandRegistryInterface::class);
        $brand->method('getProductName')->willReturn('Brand');
        $errors = [];
        $log = $this->createMock(LogRepository::class);
        $log->method('addErrorLog')->willReturnCallback(function (string $type, $data) use (&$errors) {
            $errors[] = [$type, $data];
        });
        $comments = [];
        $history = $this->createMock(OrderStatusHistoryRepositoryInterface::class);
        $history->method('save')->willReturnCallback(function ($entry) use (&$comments) {
            $comments[] = $entry->getComment();
            return $entry;
        });

        $observer = $this->getMockBuilder(Observer::class)->disableOriginalConstructor()->addMethods(['getEvent'])->getMock();
        $observer->method('getEvent')->willReturn(new DataObject(['creditmemo' => $creditmemo]));
        (new CreditmemoTendersReturned($service, $overlays, $brand, $log, new HistoryFactory(), $history))->execute($observer);

        $this->assertSame($logged === null ? [] : [$logged], array_column($errors, 0), $description . ': logged');
        if ($comment === null) {
            $this->assertSame([], $comments, $description . ': no comment');
        } else {
            $this->assertCount(1, $comments, $description . ': one comment');
            $this->assertStringContainsString($comment, $comments[0], $description);
        }
    }

    public static function memos(): array
    {
        return [
            [50.0, 4000, 'TendersReturnedAfterDeclared', 'EUR 40.00', 'a declared tender returned: 40.00 not collected'],
            [50.0, 0, null, null, 'the tenders still cover what was declared: nothing to say'],
            [0.0, 4000, null, null, 'the memo returned no tender: not looked at'],
            [50.0, new \RuntimeException('down'), 'TendersReturnedUnchecked', null, 'Two could not be read: logged, the memo stands'],
        ];
    }
}
