<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Block\Adminhtml\Order;

use Magento\Framework\Phrase;
use Magento\Sales\Model\Order;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Block\Adminhtml\Order\View;
use Two\Gateway\Model\Two;
use Two\Gateway\Service\Api\Adapter;

/**
 * TWO-27371: before anything is fulfilled, the order view's details section
 * shows the Two order id, its live state and the term it was placed on.
 */
class ViewSummaryTest extends TestCase
{
    public static function cases(): array
    {
        $standard = ['type' => 'NET_TERMS', 'duration_days' => 30];
        $endOfMonth = $standard + ['duration_days_calculated_from' => 'END_OF_MONTH'];
        return [
            // two order id, API response, API error, stored terms, expected summary
            'placed order' => ['remote-1', ['state' => 'VERIFIED'], false, $standard,
                ['id' => 'remote-1', 'state' => 'VERIFIED', 'term' => '30 days']],
            'end-of-month term' => ['remote-1', ['state' => 'CONFIRMED'], false, $endOfMonth,
                ['id' => 'remote-1', 'state' => 'CONFIRMED', 'term' => '30 days from end of month']],
            'Two unreachable' => ['remote-1', [], true, $standard,
                ['id' => 'remote-1', 'state' => null, 'term' => '30 days']],
            'no stored term' => ['remote-1', ['state' => 'VERIFIED'], false, null,
                ['id' => 'remote-1', 'state' => 'VERIFIED', 'term' => null]],
            'never reached Two' => ['', [], false, $standard, null],
        ];
    }

    #[DataProvider('cases')]
    public function testSummary(string $twoOrderId, array $response, bool $apiError, ?array $terms, ?array $expected): void
    {
        $method = $this->createMock(Two::class);
        $method->method('getErrorFromResponse')->willReturn($apiError ? new Phrase('Something went wrong') : null);
        $payment = new class ($method, $terms) {
            public function __construct(private $method, private ?array $terms)
            {
            }

            public function getMethodInstance()
            {
                return $this->method;
            }

            public function getAdditionalInformation($key = null)
            {
                return $key === 'terms' ? $this->terms : null;
            }
        };
        $order = new Order();
        $order->setData('two_order_id', $twoOrderId);
        $order->setData('store_id', 1);
        $order->setData('payment', $payment);

        $adapter = $this->createMock(Adapter::class);
        $adapter->method('execute')->willReturn($response);
        $block = (new \ReflectionClass(View::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(View::class, 'apiAdapter'))->setValue($block, $adapter);
        $block->setData('order', $order);

        $this->assertSame($expected, $block->getTwoOrderSummary());
    }
}
