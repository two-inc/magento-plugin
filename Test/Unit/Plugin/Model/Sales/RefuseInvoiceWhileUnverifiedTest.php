<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Plugin\Model\Sales;

use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\BrandOverlayRegistryInterface;
use Two\Gateway\Plugin\Model\Sales\RefuseInvoiceWhileUnverified;

/**
 * TWO-26294: an order the buyer has not verified with Two is not invoiced offline.
 */
class RefuseInvoiceWhileUnverifiedTest extends TestCase
{
    /**
     * @dataProvider cases
     */
    public function testCanInvoice(
        bool $coreAnswer,
        string $state,
        ?string $method,
        bool $canCapture,
        bool $expected,
        string $description
    ): void {
        $registry = $this->createMock(BrandOverlayRegistryInterface::class);
        $registry->method('isTwoStackMethod')->willReturnCallback(
            static fn (string $code): bool => in_array($code, ['two_payment', 'brand_payment'], true)
        );

        $order = new Order();
        $order->setState($state);
        if ($method !== null) {
            $payment = new class ($canCapture) extends Payment {
                private $canCapture;

                public function __construct(bool $canCapture)
                {
                    $this->canCapture = $canCapture;
                }

                public function canCapture(): bool
                {
                    return $this->canCapture;
                }
            };
            $payment->setMethod($method);
            $order->setPayment($payment);
        }

        $plugin = new RefuseInvoiceWhileUnverified($registry);

        $this->assertSame($expected, $plugin->afterCanInvoice($order, $coreAnswer), $description);
    }

    public static function cases(): array
    {
        $pending = Order::STATE_PENDING_PAYMENT;
        $processing = Order::STATE_PROCESSING;

        return [
            [true, $pending, 'two_payment', false, false, 'unverified Two order that would be invoiced offline is refused'],
            [true, $pending, 'brand_payment', false, false, 'same on a brand overlay method'],
            [true, $pending, 'two_payment', true, true, 'unverified Two order that captures online is left to core and Two'],
            [false, $pending, 'two_payment', true, false, 'core refusal stands where the method captures online'],
            [true, $processing, 'two_payment', false, true, 'verified Two order keeps core answer'],
            [false, $processing, 'two_payment', false, false, 'core refusal on a verified Two order stands'],
            [false, $pending, 'two_payment', false, false, 'core refusal on an unverified Two order stands'],
            [true, $pending, 'checkmo', false, true, 'pending_payment on another method keeps core answer'],
            [true, $pending, null, false, true, 'order without a payment keeps core answer'],
        ];
    }
}
