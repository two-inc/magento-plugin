<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Plugin\Magento\Quote\Model\Cart;

use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Api\CartTotalRepositoryInterface;
use Magento\Quote\Api\Data\TotalSegmentInterface;
use Magento\Quote\Api\Data\TotalSegmentInterfaceFactory;
use Magento\Quote\Api\Data\TotalsInterface;
use Magento\Quote\Model\Quote;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Plugin\Magento\Quote\Model\Cart\CartTotalRepositoryAppendSurcharge;
use Two\Gateway\Service\Order\SurchargeDisplay;

/**
 * The cart-totals API feeds Hyvä's order summary, so its surcharge segment
 * follows `tax/cart_display/price` exactly as the Luma segment does.
 */
class CartTotalRepositoryAppendSurchargeTest extends TestCase
{
    private const NET = 100.0;
    private const TAX = 21.0;

    /**
     * @return array{0: CartTotalRepositoryAppendSurcharge, 1: TotalsInterface}
     */
    private function makePlugin(string $mode, float $net = self::NET): array
    {
        $address = new class($net, self::TAX) extends Quote\Address {
            private $data;
            public function __construct(float $net, float $tax)
            {
                $this->data = [
                    'two_surcharge_amount' => $net,
                    'two_surcharge_tax_amount' => $tax,
                    'two_surcharge_description' => 'Payment terms fee - 30 days',
                ];
            }
            public function getData($key = null)
            {
                return $this->data[$key] ?? null;
            }
        };

        $quote = new class($address) extends Quote {
            private $addr;
            public function __construct($addr)
            {
                $this->addr = $addr;
            }
            public function isVirtual()
            {
                return false;
            }
            public function getShippingAddress()
            {
                return $this->addr;
            }
        };

        $quoteRepository = new class($quote) implements CartRepositoryInterface {
            private $q;
            public function __construct($q)
            {
                $this->q = $q;
            }
            public function getActive($cartId)
            {
                return $this->q;
            }
        };

        $segmentFactory = new class extends TotalSegmentInterfaceFactory {
            public function create(array $arguments = [])
            {
                return new class implements TotalSegmentInterface {
                    private $data = [];
                    public function setData(array $data)
                    {
                        $this->data = $data;
                        return $this;
                    }
                    public function getCode()
                    {
                        return $this->data['code'] ?? null;
                    }
                    public function getTitle()
                    {
                        return $this->data['title'] ?? null;
                    }
                    public function getValue()
                    {
                        return $this->data['value'] ?? null;
                    }
                };
            }
        };

        $display = $this->createMock(SurchargeDisplay::class);
        $display->method('forCart')->willReturn($mode);
        $display->method('pick')
            ->willReturnCallback(static function (string $m, float $n, float $t): float {
                return $m === SurchargeDisplay::EXCL ? $n : $n + $t;
            });

        $totals = new class implements TotalsInterface {
            private $segments = [];
            public function getTotalSegments()
            {
                return $this->segments;
            }
            public function setTotalSegments($segments)
            {
                $this->segments = $segments;
                return $this;
            }
        };

        return [
            new CartTotalRepositoryAppendSurcharge($quoteRepository, $segmentFactory, $display),
            $totals,
        ];
    }

    /**
     * @dataProvider singleSegmentModes
     */
    public function testSingleSegmentValueFollowsTheStoreTaxDisplay(string $mode, float $expected): void
    {
        [$plugin, $totals] = $this->makePlugin($mode);

        $result = $plugin->afterGet($this->createMock(CartTotalRepositoryInterface::class), $totals, 7);
        $segments = $result->getTotalSegments();

        $this->assertCount(1, $segments);
        $this->assertSame('two_surcharge', $segments[0]->getCode());
        $this->assertEqualsWithDelta($expected, (float)$segments[0]->getValue(), 1e-9);
        $this->assertSame('Payment terms fee - 30 days', $segments[0]->getTitle());
    }

    /**
     * @return array<string, array{0: string, 1: float}>
     */
    public function singleSegmentModes(): array
    {
        return [
            'excl shows net' => [SurchargeDisplay::EXCL, self::NET],
            'incl shows net plus tax' => [SurchargeDisplay::INCL, self::NET + self::TAX],
        ];
    }

    public function testBothModeAppendsPairedSegments(): void
    {
        [$plugin, $totals] = $this->makePlugin(SurchargeDisplay::BOTH);

        $segments = $plugin
            ->afterGet($this->createMock(CartTotalRepositoryInterface::class), $totals, 7)
            ->getTotalSegments();

        $this->assertCount(2, $segments);
        $this->assertSame('two_surcharge', $segments[0]->getCode());
        $this->assertEqualsWithDelta(self::NET, (float)$segments[0]->getValue(), 1e-9);
        $this->assertSame('Payment terms fee - 30 days (Excl. Tax)', $segments[0]->getTitle());
        $this->assertSame('two_surcharge_incl', $segments[1]->getCode());
        $this->assertEqualsWithDelta(self::NET + self::TAX, (float)$segments[1]->getValue(), 1e-9);
        $this->assertSame('Payment terms fee - 30 days (Incl. Tax)', $segments[1]->getTitle());
    }

    public function testNoSurchargeAppendsNothing(): void
    {
        [$plugin, $totals] = $this->makePlugin(SurchargeDisplay::INCL, 0.0);

        $segments = $plugin
            ->afterGet($this->createMock(CartTotalRepositoryInterface::class), $totals, 7)
            ->getTotalSegments();

        $this->assertSame([], $segments);
    }
}
