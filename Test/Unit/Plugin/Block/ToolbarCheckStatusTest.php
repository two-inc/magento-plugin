<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Plugin\Block;

use Magento\Backend\Block\Widget\Button\ButtonList;
use Magento\Sales\Block\Adminhtml\Order\View;
use Magento\Sales\Model\Order;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\BrandOverlayRegistryInterface;
use Two\Gateway\Api\BrandRegistryInterface;
use Two\Gateway\Plugin\Block\Widget\Button\Toolbar;

/**
 * TWO-26300: the order view offers Check status with Two only on a Two order
 * still waiting for the buyer, and only to an admin holding its resource.
 */
class ToolbarCheckStatusTest extends TestCase
{
    public static function cases(): array
    {
        return [
            'a waiting Two order, allowed' => ['two_payment', Order::STATE_PENDING_PAYMENT, 'two-1', true, true],
            'a waiting Two order, not allowed' => ['two_payment', Order::STATE_PENDING_PAYMENT, 'two-1', false, false],
            'a processing Two order' => ['two_payment', Order::STATE_PROCESSING, 'two-1', true, false],
            'a waiting Two order with no Two order id' => ['two_payment', Order::STATE_PENDING_PAYMENT, null, true, false],
            'another payment method' => ['checkmo', Order::STATE_PENDING_PAYMENT, 'two-1', true, false],
        ];
    }

    #[DataProvider('cases')]
    public function testTheButtonIsOfferedOnlyWhenItCanAct(
        string $method,
        string $state,
        ?string $twoOrderId,
        bool $allowed,
        bool $expectButton
    ): void {
        $order = (new Order())->setId(42)->setState($state)->setTwoOrderId($twoOrderId)
            ->setPayment((new Order\Payment())->setMethod($method));
        $view = (new View())->setData('order', $order);
        $buttons = new ButtonList();

        $toolbar = $this->toolbar($allowed);
        $toolbar->beforePushButtons(
            (new \ReflectionClass(\Magento\Backend\Block\Widget\Button\Toolbar::class))->newInstanceWithoutConstructor(),
            $view,
            $buttons
        );

        $description = $this->dataName();
        if (!$expectButton) {
            $this->assertSame([], $buttons->buttons, "$description: no button");
            return;
        }
        $button = $buttons->buttons[Toolbar::CHECK_STATUS_BUTTON] ?? null;
        $this->assertNotNull($button, "$description: button added");
        $this->assertSame('Check status with Two', (string)$button['label'], "$description: label");
        $this->assertStringContainsString(
            'f.method="post";f.action="https://shop.test/admin/two/order/checkStatus/order_id/42/"',
            $button['onclick'],
            "$description: posts to the check"
        );
        $this->assertStringContainsString('k.value=window.FORM_KEY', $button['onclick'], "$description: carries the form key");
    }

    private function toolbar(bool $allowed): Toolbar
    {
        $overlay = $this->createMock(BrandOverlayRegistryInterface::class);
        $overlay->method('isTwoStackMethod')->willReturnCallback(static fn ($code) => $code === 'two_payment');
        $brand = $this->createMock(BrandRegistryInterface::class);
        $brand->method('getProductName')->willReturn('Two');
        $authorization = new class ($allowed) {
            private $allowed;

            public function __construct($allowed)
            {
                $this->allowed = $allowed;
            }

            public function isAllowed($resource)
            {
                return $this->allowed && $resource === 'Two_Gateway::check_pending_order';
            }
        };
        $url = new class {
            public function getUrl($path, $params = [])
            {
                $query = '';
                foreach ($params as $key => $value) {
                    $query .= "$key/$value/";
                }
                return "https://shop.test/admin/$path/$query";
            }
        };

        $reflection = new \ReflectionClass(Toolbar::class);
        $toolbar = $reflection->newInstanceWithoutConstructor();
        $values = ['overlayRegistry' => $overlay, 'brandRegistry' => $brand, 'authorization' => $authorization, 'urlBuilder' => $url];
        foreach ($values as $property => $value) {
            $reflection->getProperty($property)->setValue($toolbar, $value);
        }
        return $toolbar;
    }
}
