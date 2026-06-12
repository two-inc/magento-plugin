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

    public function testRejectsNonNumericMinimumOrderAmount(): void
    {
        // brand.xsd marks amount required but nothing validates the schema
        // at runtime; a typo'd amount (here a comma decimal) would coerce
        // to 0.0 and silently disable the gate, so the Loader must refuse
        // to load it.
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/minimum_order/');

        $this->loaderFor('invalid_minimum')->load();
    }

    public function testZeroMinimumOrderAmountMeansNoMinimum(): void
    {
        // An explicit zero is valid and means "no minimum" - same semantics
        // as the checkout-api merchant config.
        $brands = $this->loaderFor('zero_minimum')->load();

        $this->assertNull($brands['two_payment']->getMinimumOrder());
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
