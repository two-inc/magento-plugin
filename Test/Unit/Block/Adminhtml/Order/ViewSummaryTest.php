<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Block\Adminhtml\Order;

use Magento\Framework\Phrase;
use Magento\Framework\Phrase\RendererInterface;
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
    /** What the adapter returns when its bounded call runs out. */
    private const TIMED_OUT = ['error_code' => 400, 'error_message' => 'Operation timed out after 5000 milliseconds'];

    public static function cases(): array
    {
        $standard = ['type' => 'NET_TERMS', 'duration_days' => 30];
        $endOfMonth = $standard + ['duration_days_calculated_from' => 'END_OF_MONTH'];
        return [
            // two order id, API response, API error, stored terms, expected summary
            'placed order' => ['remote-1', ['state' => 'VERIFIED'], false, $standard,
                ['id' => 'remote-1', 'state' => 'Verified', 'term' => '30 days']],
            'end-of-month term' => ['remote-1', ['state' => 'CONFIRMED'], false, $endOfMonth,
                ['id' => 'remote-1', 'state' => 'Confirmed', 'term' => '30 days from end of month']],
            'fulfilment needing manual resolution' => ['remote-1', ['state' => 'FULFILMENT_NEEDS_MANUAL_RESOLUTION'], false, $standard,
                ['id' => 'remote-1', 'state' => 'Fulfilment needs manual resolution', 'term' => '30 days']],
            'state Two adds later shows as named' => ['remote-1', ['state' => 'SOME_NEW_STATE'], false, $standard,
                ['id' => 'remote-1', 'state' => 'SOME_NEW_STATE', 'term' => '30 days']],
            'Two unreachable' => ['remote-1', [], true, $standard,
                ['id' => 'remote-1', 'state' => null, 'term' => '30 days']],
            'Two timed out' => ['remote-1', self::TIMED_OUT, true, $standard,
                ['id' => 'remote-1', 'state' => null, 'term' => '30 days']],
            'no stored term' => ['remote-1', ['state' => 'VERIFIED'], false, null,
                ['id' => 'remote-1', 'state' => 'Verified', 'term' => null]],
            'never reached Two' => ['', [], false, $standard, null],
        ];
    }

    #[DataProvider('cases')]
    public function testSummary(string $twoOrderId, array $response, bool $apiError, ?array $terms, ?array $expected): void
    {
        $block = $this->block($twoOrderId, $response, $apiError, $terms, $calls);

        $this->assertSame($expected, $block->getTwoOrderSummary());
    }

    /**
     * The state label is looked up under its own key, not the bare word, so
     * another module's translation of "Confirmed" cannot replace it.
     */
    public function testStateIsTranslatedUnderItsOwnKey(): void
    {
        Phrase::setRenderer(new class implements RendererInterface {
            public function render(array $source, array $arguments)
            {
                $text = end($source);
                return ['Provider order state: Confirmed' => 'Bevestigd', 'Confirmed' => 'Wrong'][$text] ?? $text;
            }
        });
        try {
            $block = $this->block('remote-1', ['state' => 'CONFIRMED'], false, null, $calls);
            $this->assertSame('Bevestigd', $block->getTwoOrderSummary()['state']);
        } finally {
            Phrase::setRenderer(null);
        }
    }

    /**
     * Every read the order view makes to Two is bounded, so a hung Two cannot
     * stall the page, and a read that runs out leaves its rows off.
     */
    public function testEveryReadIsBoundedAndATimeoutLeavesThePageRendering(): void
    {
        $block = $this->block('remote-1', self::TIMED_OUT, true, null, $calls);

        $this->assertSame(['id' => 'remote-1', 'state' => null, 'term' => null], $block->getTwoOrderSummary());
        $this->assertSame([], $block->getTwoOrderFulfillments());
        $this->assertSame(
            [['/v1/order/remote-1', 5], ['/v1/order/remote-1/fulfillments', 5]],
            array_map(fn(array $args) => [$args[0], $args[6] ?? null], $calls)
        );
    }

    private function block(string $twoOrderId, array $response, bool $apiError, ?array $terms, ?array &$calls): View
    {
        $calls = [];
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
        $adapter->method('execute')->willReturnCallback(function (...$args) use (&$calls, $response) {
            $calls[] = $args;
            return $response;
        });
        $block = (new \ReflectionClass(View::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(View::class, 'apiAdapter'))->setValue($block, $adapter);
        $block->setData('order', $order);

        return $block;
    }
}
