<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Plugin\Magento\Sales\Ui\Component\Listing\Column;

use Magento\Sales\Ui\Component\Listing\Column\Price;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\BrandRegistryInterface;
use Two\Gateway\Plugin\Magento\Sales\Ui\Component\Listing\Column\BrandSurchargeColumnLabel;

class BrandSurchargeColumnLabelTest extends TestCase
{
    private function plugin(string $productName): BrandSurchargeColumnLabel
    {
        $registry = $this->createMock(BrandRegistryInterface::class);
        $registry->method('getProductName')->willReturn($productName);

        return new BrandSurchargeColumnLabel($registry);
    }

    private function column(string $name, string $label): Price
    {
        return new Price(['name' => $name, 'config' => ['label' => $label]]);
    }

    /**
     * @dataProvider columns
     */
    public function testOnlyTheSurchargeColumnTakesTheBrandsProductName(
        string $columnName,
        string $label,
        string $productName,
        string $expected,
        string $description
    ): void {
        $column = $this->column($columnName, $label);
        $this->plugin($productName)->beforePrepare($column);

        $this->assertSame($expected, $column->getData('config')['label'], $description);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string, 4: string}>
     */
    public static function columns(): array
    {
        return [
            'vanilla' => ['two_surcharge_amount', '%1 surcharge', 'Two', 'Two surcharge', 'the unbranded install reads as before'],
            'overlay' => ['two_surcharge_amount', '%1 surcharge', 'Acme Pay', 'Acme Pay surcharge', 'a debranded install names its own product'],
            'sibling column' => ['grand_total', 'Grand Total (Base)', 'Acme Pay', 'Grand Total (Base)', 'every other price column in the grid is untouched'],
        ];
    }

    public function testAColumnWithNoLabelIsLeftAlone(): void
    {
        $column = new Price(['name' => 'two_surcharge_amount', 'config' => []]);
        $this->plugin('Acme Pay')->beforePrepare($column);

        $this->assertSame([], $column->getData('config'));
    }
}
