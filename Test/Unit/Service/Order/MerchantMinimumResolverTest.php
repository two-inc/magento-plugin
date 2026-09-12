<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Service\Order;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Service\Order\MerchantMinimumResolver;

class MerchantMinimumResolverTest extends TestCase
{
    /** @var ScopeConfigInterface|\PHPUnit\Framework\MockObject\MockObject */
    private $scopeConfig;

    /** @var MerchantMinimumResolver */
    private $resolver;

    protected function setUp(): void
    {
        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $this->resolver = new MerchantMinimumResolver($this->scopeConfig);
    }

    private function stubConfig(float $amount, string $basis = ''): void
    {
        $this->scopeConfig->method('getValue')->willReturnMap([
            ['payment/two_payment/merchant_minimum_order', ScopeInterface::SCOPE_STORE, 1, $amount],
            ['payment/two_payment/merchant_minimum_order_basis', ScopeInterface::SCOPE_STORE, 1, $basis],
        ]);
    }

    public function testNullWhenNoMerchantMinimumConfigured(): void
    {
        $this->stubConfig(0.0);
        $this->assertNull($this->resolver->resolve('two_payment', 'GBP', null, 1));
    }

    public function testNullWhenBaseCurrencyUnresolved(): void
    {
        $this->stubConfig(35.0);
        $this->assertNull($this->resolver->resolve('two_payment', '', null, 1));
    }

    public function testResolvesConfiguredMerchantMinimum(): void
    {
        $this->stubConfig(35.0, 'net');
        $this->assertSame(
            ['amount' => 35.0, 'currency' => 'GBP', 'basis' => 'net'],
            $this->resolver->resolve('two_payment', 'GBP', null, 1)
        );
    }

    public function testBasisFallsBackToPlatformBasisWhenAdminValueInvalid(): void
    {
        $this->stubConfig(35.0, 'invalid');
        $this->assertSame(
            ['amount' => 35.0, 'currency' => 'GBP', 'basis' => 'gross'],
            $this->resolver->resolve('two_payment', 'GBP', ['basis' => 'gross'], 1)
        );
    }

    public function testBasisFallsBackToGrossWhenNoPlatformMinimumEither(): void
    {
        $this->stubConfig(35.0, '');
        $this->assertSame(
            ['amount' => 35.0, 'currency' => 'GBP', 'basis' => 'gross'],
            $this->resolver->resolve('two_payment', 'GBP', null, 1)
        );
    }

    public function testScopedByPaymentMethodCode(): void
    {
        $this->scopeConfig->method('getValue')->willReturnMap([
            ['payment/acme_payment/merchant_minimum_order', ScopeInterface::SCOPE_STORE, 1, 50.0],
            ['payment/acme_payment/merchant_minimum_order_basis', ScopeInterface::SCOPE_STORE, 1, 'net'],
            ['payment/two_payment/merchant_minimum_order', ScopeInterface::SCOPE_STORE, 1, 0.0],
        ]);

        $this->assertNull($this->resolver->resolve('two_payment', 'GBP', null, 1));
        $this->assertSame(
            ['amount' => 50.0, 'currency' => 'GBP', 'basis' => 'net'],
            $this->resolver->resolve('acme_payment', 'GBP', null, 1)
        );
    }
}
