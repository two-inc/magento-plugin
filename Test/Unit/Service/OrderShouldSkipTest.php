<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Service;

use Magento\Bundle\Model\Product\Price;
use Magento\Catalog\Model\Product\Type;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Service\Order;

class OrderShouldSkipTest extends TestCase
{
    /** @var Order|\PHPUnit\Framework\MockObject\MockObject */
    private $orderService;

    protected function setUp(): void
    {
        $this->orderService = $this->getMockForAbstractClass(
            Order::class,
            [],
            '',
            false // don't call constructor
        );
    }

    /**
     * Create a mock item with getProductType() and getProduct()->getPriceType().
     */
    private function makeItem(string $productType, ?int $priceType = null): object
    {
        $item = new \stdClass();
        $item->productType = $productType;
        $item->priceType = $priceType;

        // We need an object with getProductType() and getProduct() methods.
        // Use an anonymous class for clean mocking.
        return new class($productType, $priceType) {
            private $productType;
            private $priceType;

            public function __construct(string $productType, ?int $priceType)
            {
                $this->productType = $productType;
                $this->priceType = $priceType;
            }

            public function getProductType(): string
            {
                return $this->productType;
            }

            public function getProduct(): object
            {
                $priceType = $this->priceType;
                return new class($priceType) {
                    private $priceType;

                    public function __construct(?int $priceType)
                    {
                        $this->priceType = $priceType;
                    }

                    public function getPriceType(): ?int
                    {
                        return $this->priceType;
                    }
                };
            }
        };
    }

    // ── Bundle items (no parent) ────────────────────────────────────────

    public function testBundleDynamicPricingIsSkipped(): void
    {
        $item = $this->makeItem(Type::TYPE_BUNDLE, Price::PRICE_TYPE_DYNAMIC);

        $this->assertTrue($this->orderService->shouldSkip(null, $item));
    }

    public function testBundleFixedPricingIsNotSkipped(): void
    {
        $item = $this->makeItem(Type::TYPE_BUNDLE, Price::PRICE_TYPE_FIXED);

        $this->assertFalse($this->orderService->shouldSkip(null, $item));
    }

    // ── Simple items (no parent) ────────────────────────────────────────

    public function testSimpleItemNoParentIsNotSkipped(): void
    {
        $item = $this->makeItem('simple');

        $this->assertFalse($this->orderService->shouldSkip(null, $item));
    }

    // ── Child items with non-bundle parent ──────────────────────────────

    public function testChildOfConfigurableIsSkipped(): void
    {
        $parent = $this->makeItem('configurable');
        $item = $this->makeItem('simple');

        $this->assertTrue($this->orderService->shouldSkip($parent, $item));
    }

    public function testChildOfGroupedIsSkipped(): void
    {
        $parent = $this->makeItem('grouped');
        $item = $this->makeItem('simple');

        $this->assertTrue($this->orderService->shouldSkip($parent, $item));
    }

    // ── Child items with bundle parent ──────────────────────────────────

    public function testChildOfBundleFixedPriceIsSkipped(): void
    {
        $parent = $this->makeItem(Type::TYPE_BUNDLE, Price::PRICE_TYPE_FIXED);
        $item = $this->makeItem('simple');

        $this->assertTrue($this->orderService->shouldSkip($parent, $item));
    }

    public function testChildOfBundleDynamicPriceIsNotSkipped(): void
    {
        $parent = $this->makeItem(Type::TYPE_BUNDLE, Price::PRICE_TYPE_DYNAMIC);
        $item = $this->makeItem('simple');

        $this->assertFalse($this->orderService->shouldSkip($parent, $item));
    }
}
