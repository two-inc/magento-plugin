<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Model\Brand;

use Magento\Framework\Component\ComponentRegistrar;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Model\Brand\Loader;

/**
 * Focused on the brand.xml -> Descriptor mapping for the
 * <surcharge_rounding_steps> element added for the Rounding Step
 * dropdown (ABN-457). Loader does no runtime XSD validation, so the
 * parse/validate guards here are the only safety net.
 */
class LoaderTest extends TestCase
{
    /** @var string[] dirs to clean up */
    private array $tmpDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tmpDirs as $dir) {
            @unlink($dir . '/etc/brand.xml');
            @rmdir($dir . '/etc');
            @rmdir($dir);
        }
        $this->tmpDirs = [];
    }

    public function testRoundingStepsAreParsedDedupedAndSortedAscending(): void
    {
        $loader = $this->loaderForBrandBody(
            '<surcharge_rounding_steps>'
            . '<step>1.00</step><step>0.50</step><step>0.50</step>'
            . '<step>0.10</step><step>5</step>'
            . '</surcharge_rounding_steps>'
        );

        $descriptor = $loader->load()['two_payment'];

        // 0.5 == 0.50 collapses; ascending numeric order.
        $this->assertSame([0.1, 0.5, 1.0, 5.0], $descriptor->getSurchargeRoundingSteps());
    }

    public function testRoundingStepsFallBackToDefaultWhenElementAbsent(): void
    {
        $loader = $this->loaderForBrandBody('');

        $descriptor = $loader->load()['two_payment'];

        $this->assertSame(
            [0.1, 0.5, 1.0, 5.0, 10.0],
            $descriptor->getSurchargeRoundingSteps()
        );
    }

    public function testRoundingStepsFallBackToDefaultWhenElementEmpty(): void
    {
        $loader = $this->loaderForBrandBody(
            '<surcharge_rounding_steps></surcharge_rounding_steps>'
        );

        $descriptor = $loader->load()['two_payment'];

        $this->assertSame(
            [0.1, 0.5, 1.0, 5.0, 10.0],
            $descriptor->getSurchargeRoundingSteps()
        );
    }

    /**
     * @dataProvider invalidStepProvider
     */
    public function testInvalidRoundingStepThrows(string $stepValue): void
    {
        $loader = $this->loaderForBrandBody(
            '<surcharge_rounding_steps><step>' . $stepValue . '</step></surcharge_rounding_steps>'
        );

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('invalid surcharge rounding step');
        $loader->load();
    }

    /** @return array<string,array{0:string}> */
    public static function invalidStepProvider(): array
    {
        return [
            'non-numeric' => ['abc'],
            'zero' => ['0'],
            'negative' => ['-1.00'],
        ];
    }

    private function loaderForBrandBody(string $roundingStepsXml): Loader
    {
        $dir = sys_get_temp_dir() . '/two_brand_test_' . uniqid('', true);
        mkdir($dir . '/etc', 0777, true);
        $this->tmpDirs[] = $dir;

        $xml = '<?xml version="1.0"?>'
            . '<config><brand code="two_payment" section_prefix="two" tab_sort_order="500">'
            . '<provider>Two</provider><product_name>Two</product_name>'
            . '<tab_label>Two</tab_label>'
            . '<checkout_url_template>https://%s.two.inc</checkout_url_template>'
            . '<api_base_url>https://api.two.inc</api_base_url>'
            . '<available_payment_terms><term>30</term></available_payment_terms>'
            . $roundingStepsXml
            . '<admin_resource>Magento_Sales::config_sales</admin_resource>'
            . '</brand></config>';
        file_put_contents($dir . '/etc/brand.xml', $xml);

        $registrar = $this->createMock(ComponentRegistrar::class);
        $registrar->method('getPaths')
            ->with(ComponentRegistrar::MODULE)
            ->willReturn(['Two_Gateway' => $dir]);

        return new Loader($registrar);
    }
}
