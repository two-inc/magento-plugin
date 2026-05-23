<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Plugin\Config\Structure;

use Magento\Config\Model\Config\Structure\Element\Section;
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

    /**
     * Build an anonymous subclass of Section that overrides getId(). We can't
     * use createMock(Section::class) because getId comes from
     * Magento\Framework\Data\Structure\Element's __call magic in the CI test
     * stub (no declared method, PHPUnit throws MethodCannotBeConfiguredException),
     * and we can't use ReflectionClass::newInstanceWithoutConstructor + setData
     * because the test-bundled Section stub doesn't extend DataObject and so
     * has no setData. Skipping the parent constructor keeps us independent of
     * its required arguments.
     */
    private function section(string $id): Section
    {
        return new class($id) extends Section {
            // phpcs:disable
            public function __construct(private string $sectionId)
            {
                // skip parent constructor — only getId() is exercised by the plugin
            }
            public function getId()
            {
                return $this->sectionId;
            }
            // phpcs:enable
        };
    }

    public function testPassThroughWhenSectionAlreadyHidden(): void
    {
        $plugin = $this->plugin(true, true);
        $this->assertFalse($plugin->afterIsVisible($this->section('two_payment'), false));
    }

    public function testPassThroughForUnrelatedSection(): void
    {
        $plugin = $this->plugin(true, true);
        $this->assertTrue($plugin->afterIsVisible($this->section('catalog'), true));
    }

    public function testPassThroughWhenNoOverlayInstalled(): void
    {
        $plugin = $this->plugin(false, true);
        $this->assertTrue($plugin->afterIsVisible($this->section('two_payment'), true));
    }

    public function testPassThroughWhenHideFlagDisabled(): void
    {
        $plugin = $this->plugin(true, false);
        $this->assertTrue($plugin->afterIsVisible($this->section('two_payment'), true));
    }

    public function testHidesTwoPaymentSection(): void
    {
        $plugin = $this->plugin(true, true);
        $this->assertFalse($plugin->afterIsVisible($this->section('two_payment'), true));
    }

    public function testHidesTwoGeneralSection(): void
    {
        $plugin = $this->plugin(true, true);
        $this->assertFalse($plugin->afterIsVisible($this->section('two_general'), true));
    }

    public function testHidesTwoSearchSection(): void
    {
        $plugin = $this->plugin(true, true);
        $this->assertFalse($plugin->afterIsVisible($this->section('two_search'), true));
    }
}
