<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Block\Payment;

use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Framework\View\Element\Template\Context;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\BrandRegistryInterface;
use Two\Gateway\Block\Payment\Info;

/**
 * The order id row on the native admin Payment Information section must
 * carry the active brand's product name in its label, not a hardcoded
 * "Two" — this is what lets a brand overlay (e.g. ABN) get its own
 * "<brand> order id" label for free from BrandRegistryInterface instead
 * of needing its own suppression/override.
 */
class InfoTest extends TestCase
{
    /**
     * @dataProvider specificInformationProvider
     */
    public function testSpecificInformation(
        string $areaCode,
        string $productName,
        string $orderId,
        array $expected,
        string $description
    ): void {
        $brandRegistry = $this->createMock(BrandRegistryInterface::class);
        $brandRegistry->method('getProductName')->willReturn($productName);

        $appState = $this->createMock(State::class);
        $appState->method('getAreaCode')->willReturn($areaCode);

        // Order/Payment are Test/Stubs/SalesModels.php data bags with real
        // magic get/set, matching how two_order_id is actually read off the
        // flat-table column in production — not a PHPUnit mock.
        $order = new Order();
        $order->setTwoOrderId($orderId);

        $payment = new Payment();
        $payment->setOrder($order);

        $block = new Info($this->createMock(Context::class), $brandRegistry, $appState);
        $block->setData('info', $payment);

        self::assertSame($expected, $block->getSpecificInformation(), $description);
    }

    public function specificInformationProvider(): array
    {
        return [
            'admin, order id present, base brand' => [
                Area::AREA_ADMINHTML,
                'Two',
                'ord_123',
                ['Two order id' => 'ord_123'],
                'base brand label must use the resolved product name, not be hardcoded',
            ],
            'admin, order id present, ABN brand' => [
                Area::AREA_ADMINHTML,
                'Business Invoice',
                'ord_456',
                ['Business Invoice order id' => 'ord_456'],
                'overlay brand must get its own label with no code change',
            ],
            'admin, no order id yet' => [
                Area::AREA_ADMINHTML,
                'Two',
                '',
                [],
                'no order id means no row, not an empty-value row',
            ],
            'frontend, order id present' => [
                Area::AREA_FRONTEND,
                'Two',
                'ord_123',
                [],
                'order id is admin-only, must not leak into the buyer-facing order view',
            ],
        ];
    }
}
