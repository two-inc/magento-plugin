<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Model\Brand;

use Magento\Framework\Component\ComponentRegistrar;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Model\Brand\Loader;

class LoaderTest extends TestCase
{
    private function loaderFor(string $fixture): Loader
    {
        $registrar = $this->getMockBuilder(ComponentRegistrar::class)
            ->onlyMethods(['getPaths'])
            ->getMock();
        $registrar->method('getPaths')->willReturn([__DIR__ . '/_files/' . $fixture]);
        return new Loader($registrar);
    }

    public function testRejectsZeroMinimumOrderAmount(): void
    {
        // brand.xsd marks amount required but nothing validates the schema
        // at runtime; a zero/typo'd amount would silently disable the gate,
        // so the Loader must refuse to load it.
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/minimum_order/');

        $this->loaderFor('invalid_minimum')->load();
    }

    public function testNormalisesMinimumOrderCurrency(): void
    {
        $brands = $this->loaderFor('lowercase_currency')->load();

        // ' eur ' in the xml must not force the FX branch into permanent
        // fail-closed against uppercase quote currency codes
        $this->assertSame(
            ['amount' => 250.0, 'currency' => 'EUR', 'basis' => 'net'],
            $brands['two_payment']->getMinimumOrder()
        );
    }
}
