<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Plugin\Config\Structure;

use Magento\Config\Model\Config\Structure;
use Magento\Framework\App\Config\ScopeConfigInterface;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\BrandOverlayRegistryInterface;
use Two\Gateway\Plugin\Config\Structure\HidePaymentSection;

class HidePaymentSectionTest extends TestCase
{
    private function plugin(bool $overlayInstalled, bool $hideFlag): HidePaymentSection
    {
        $registry = $this->createMock(BrandOverlayRegistryInterface::class);
        $registry->method('isOverlayInstalled')->willReturn($overlayInstalled);

        $scope = $this->createMock(ScopeConfigInterface::class);
        $scope->method('getValue')
            ->with('payment/two_payment/hide_when_overlay_installed')
            ->willReturn($hideFlag ? '1' : '0');

        return new HidePaymentSection($registry, $scope);
    }

    public function testPassThroughForUnrelatedSection(): void
    {
        $plugin = $this->plugin(true, true);
        $sentinel = new \stdClass();
        $this->assertSame(
            $sentinel,
            $plugin->afterGetElement($this->createMock(Structure::class), $sentinel, 'payment')
        );
    }

    public function testPassThroughWhenNoOverlayInstalled(): void
    {
        $plugin = $this->plugin(false, true);
        $sentinel = new \stdClass();
        $this->assertSame(
            $sentinel,
            $plugin->afterGetElement($this->createMock(Structure::class), $sentinel, 'two_payment')
        );
    }

    public function testPassThroughWhenHideFlagDisabled(): void
    {
        $plugin = $this->plugin(true, false);
        $sentinel = new \stdClass();
        $this->assertSame(
            $sentinel,
            $plugin->afterGetElement($this->createMock(Structure::class), $sentinel, 'two_payment')
        );
    }

    public function testHidesTwoPaymentSectionWhenOverlayAndFlagBothSet(): void
    {
        $plugin = $this->plugin(true, true);
        $this->assertNull(
            $plugin->afterGetElement($this->createMock(Structure::class), new \stdClass(), 'two_payment')
        );
    }

    public function testHidesTwoGeneralSectionWhenOverlayAndFlagBothSet(): void
    {
        $plugin = $this->plugin(true, true);
        $this->assertNull(
            $plugin->afterGetElement($this->createMock(Structure::class), new \stdClass(), 'two_general')
        );
    }

    public function testHidesNestedGroupUnderTwoPayment(): void
    {
        $plugin = $this->plugin(true, true);
        $this->assertNull(
            $plugin->afterGetElement(
                $this->createMock(Structure::class),
                new \stdClass(),
                'two_payment/payment_method'
            )
        );
    }

    public function testHidesNestedGroupUnderTwoGeneral(): void
    {
        $plugin = $this->plugin(true, true);
        $this->assertNull(
            $plugin->afterGetElement(
                $this->createMock(Structure::class),
                new \stdClass(),
                'two_general/general'
            )
        );
    }
}
