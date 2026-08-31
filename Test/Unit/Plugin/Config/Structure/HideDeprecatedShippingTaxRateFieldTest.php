<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Plugin\Config\Structure;

use Magento\Config\Model\Config\Structure\Element\Field;
use Magento\Framework\App\RequestInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\Config\RepositoryInterface as ConfigRepository;
use Two\Gateway\Plugin\Config\Structure\HideDeprecatedShippingTaxRateField;

class HideDeprecatedShippingTaxRateFieldTest extends TestCase
{
    private function plugin(?float $storedRate): HideDeprecatedShippingTaxRateField
    {
        $configRepository = $this->createMock(ConfigRepository::class);
        $configRepository->method('getDefaultShippingTaxRate')->willReturn($storedRate);

        $request = $this->createMock(RequestInterface::class);
        $request->method('getParam')->willReturn(null);

        return new HideDeprecatedShippingTaxRateField(
            $configRepository,
            $request,
            $this->createMock(StoreManagerInterface::class)
        );
    }

    /**
     * Anonymous Field subclass overriding getId() only — mirrors
     * HidePaymentSectionTest::section(), for the same reason: the CI
     * test-stub Field has no declared getId() for createMock() to
     * configure, and no setData() to populate via reflection.
     */
    private function field(string $id): Field
    {
        return new class ($id) extends Field {
            // phpcs:disable
            public function __construct(private string $fieldId)
            {
            }
            public function getId()
            {
                return $this->fieldId;
            }
            // phpcs:enable
        };
    }

    public function testPassThroughWhenAlreadyHidden(): void
    {
        $plugin = $this->plugin(21.5);
        $this->assertFalse($plugin->afterIsVisible($this->field('default_shipping_tax_rate'), false));
    }

    public function testPassThroughForUnrelatedField(): void
    {
        $plugin = $this->plugin(null);
        $this->assertTrue($plugin->afterIsVisible($this->field('debug'), true));
    }

    public function testHiddenWhenNoValueIsStored(): void
    {
        $plugin = $this->plugin(null);
        $this->assertFalse($plugin->afterIsVisible($this->field('default_shipping_tax_rate'), true));
    }

    public function testVisibleWhenAValueIsAlreadyStored(): void
    {
        $plugin = $this->plugin(21.5);
        $this->assertTrue($plugin->afterIsVisible($this->field('default_shipping_tax_rate'), true));
    }

    /**
     * A configured 0% is a real declaration, not "unset" — must stay visible.
     */
    public function testVisibleWhenTheStoredValueIsZero(): void
    {
        $plugin = $this->plugin(0.0);
        $this->assertTrue($plugin->afterIsVisible($this->field('default_shipping_tax_rate'), true));
    }
}
