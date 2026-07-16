<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Model;

use Magento\Quote\Model\Quote;
use Magento\Store\Model\Store;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\CurrencyRatesProviderInterface;
use Two\Gateway\Api\Log\RepositoryInterface as LogRepository;
use Two\Gateway\Model\Two;
use Two\Gateway\Service\Order\MinimumOrderGate;
use Two\Gateway\Service\Order\MinimumOrderProvider;

/**
 * Tests for Two::getMinimumOrderVisibility()'s split fail policy: an
 * unprojectable PLATFORM floor sets `unresolved` (client gate hides the
 * method, fail-closed), while an unprojectable MERCHANT minimum is simply
 * omitted from `minimums` (fail-open) and never hides the method.
 */
class TwoMinimumOrderVisibilityTest extends TestCase
{
    /** @var CurrencyRatesProviderInterface|\PHPUnit\Framework\MockObject\MockObject */
    private $ratesProvider;

    /** @var MinimumOrderProvider|\PHPUnit\Framework\MockObject\MockObject */
    private $minimumOrderProvider;

    /** @var Two */
    private $model;

    protected function setUp(): void
    {
        $this->ratesProvider = $this->createMock(CurrencyRatesProviderInterface::class);
        $this->minimumOrderProvider = $this->createMock(MinimumOrderProvider::class);

        // Real gate (mocked rates provider) so the projection semantics under
        // test are the shipped ones, not a mock's.
        $gate = new MinimumOrderGate($this->ratesProvider, $this->createMock(LogRepository::class));

        // Anonymous subclass: skip the heavyweight constructor and stub the
        // admin-config reads buildMerchantMinimum() depends on.
        $this->model = new class extends Two {
            /** @var array<string, mixed> */
            public $configData = [];

            public function __construct()
            {
            }

            public function getConfigData($field, $storeId = null)
            {
                return $this->configData[$field] ?? null;
            }
        };

        $ref = new \ReflectionClass(Two::class);
        foreach (['minimumOrderGate' => $gate, 'minimumOrderProvider' => $this->minimumOrderProvider] as $name => $value) {
            $ref->getProperty($name)->setValue($this->model, $value);
        }
    }

    /**
     * @return Quote|\PHPUnit\Framework\MockObject\MockObject
     */
    private function quote(string $quoteCurrency, string $baseCurrency)
    {
        $store = $this->createMock(Store::class);
        $store->method('getBaseCurrencyCode')->willReturn($baseCurrency);

        $quote = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getQuoteCurrencyCode', 'getStoreId', 'getStore'])
            ->getMock();
        $quote->method('getQuoteCurrencyCode')->willReturn($quoteCurrency);
        $quote->method('getStoreId')->willReturn(1);
        $quote->method('getStore')->willReturn($store);
        return $quote;
    }

    public function testUnresolvablePlatformFloorSetsUnresolved(): void
    {
        // Platform floor in EUR, display currency SEK, no EUR->SEK rate:
        // the floor cannot be projected — fail closed, hide the method.
        $this->minimumOrderProvider->method('getMinimum')
            ->willReturn(['amount' => 250.0, 'currency' => 'EUR', 'basis' => 'net']);
        $this->ratesProvider->method('getRate')->willReturn(null);

        $result = $this->model->getMinimumOrderVisibility($this->quote('SEK', 'SEK'));

        $this->assertTrue($result['unresolved']);
        $this->assertSame([], $result['minimums']);
    }

    public function testUnresolvableMerchantMinimumAloneDoesNotSetUnresolved(): void
    {
        // Platform floor in the display currency (projects without FX); the
        // merchant minimum is denominated in the base currency (USD) with no
        // USD->EUR rate. Fail open: the merchant bar is simply absent from
        // `minimums`, and the method is NOT hidden.
        $this->minimumOrderProvider->method('getMinimum')
            ->willReturn(['amount' => 250.0, 'currency' => 'EUR', 'basis' => 'net']);
        $this->model->configData = [
            'merchant_minimum_order' => 500.0,
            'merchant_minimum_order_basis' => 'net',
        ];
        $this->ratesProvider->method('getRate')
            ->with('USD', 'EUR', 1)
            ->willReturn(null);

        $result = $this->model->getMinimumOrderVisibility($this->quote('EUR', 'USD'));

        $this->assertFalse($result['unresolved']);
        $this->assertSame([['amount' => 250.0, 'basis' => 'net']], $result['minimums']);
    }

    public function testNanRatePlatformFloorSetsUnresolved(): void
    {
        // A NaN rate is as unusable as a missing one; it must set
        // `unresolved` (fail closed), not leak NAN into `minimums`.
        $this->minimumOrderProvider->method('getMinimum')
            ->willReturn(['amount' => 250.0, 'currency' => 'EUR', 'basis' => 'net']);
        $this->ratesProvider->method('getRate')->willReturn(NAN);

        $result = $this->model->getMinimumOrderVisibility($this->quote('SEK', 'SEK'));

        $this->assertTrue($result['unresolved']);
        $this->assertSame([], $result['minimums']);
    }

    public function testNanRateMerchantMinimumIsOmittedAndDoesNotSetUnresolved(): void
    {
        // NaN on the merchant bar's rate fails open: bar omitted, method
        // stays visible — same treatment as a missing rate.
        $this->minimumOrderProvider->method('getMinimum')
            ->willReturn(['amount' => 250.0, 'currency' => 'EUR', 'basis' => 'net']);
        $this->model->configData = [
            'merchant_minimum_order' => 500.0,
            'merchant_minimum_order_basis' => 'net',
        ];
        $this->ratesProvider->method('getRate')
            ->with('USD', 'EUR', 1)
            ->willReturn(NAN);

        $result = $this->model->getMinimumOrderVisibility($this->quote('EUR', 'USD'));

        $this->assertFalse($result['unresolved']);
        $this->assertSame([['amount' => 250.0, 'basis' => 'net']], $result['minimums']);
    }

    public function testUnresolvableDisplayCurrencyWithMerchantMinimumOnlyFailsOpen(): void
    {
        // No platform floor, merchant minimum configured, but neither quote
        // nor store base currency resolvable: the merchant bar cannot even be
        // constructed (base currency unknown) — fail open, method visible.
        // Before the fix an empty display currency blanket-set `unresolved`
        // even with no platform floor in play.
        $this->minimumOrderProvider->method('getMinimum')->willReturn(null);
        $this->model->configData = [
            'merchant_minimum_order' => 500.0,
            'merchant_minimum_order_basis' => 'net',
        ];

        $result = $this->model->getMinimumOrderVisibility($this->quote('', ''));

        $this->assertFalse($result['unresolved']);
        $this->assertSame([], $result['minimums']);
    }

    public function testUnresolvableDisplayCurrencyWithPlatformFloorStillFailsClosed(): void
    {
        // Platform floor active but the display currency is unresolvable:
        // the floor cannot be projected — fail closed via the normal
        // per-minimum branch (no rate exists for an empty currency code).
        $this->minimumOrderProvider->method('getMinimum')
            ->willReturn(['amount' => 250.0, 'currency' => 'EUR', 'basis' => 'net']);
        $this->ratesProvider->method('getRate')->willReturn(null);

        $result = $this->model->getMinimumOrderVisibility($this->quote('', ''));

        $this->assertTrue($result['unresolved']);
        $this->assertSame([], $result['minimums']);
    }

    public function testBothMinimumsShownWhenProjectable(): void
    {
        $this->minimumOrderProvider->method('getMinimum')
            ->willReturn(['amount' => 250.0, 'currency' => 'EUR', 'basis' => 'net']);
        $this->model->configData = [
            'merchant_minimum_order' => 500.0,
            'merchant_minimum_order_basis' => 'net',
        ];
        $this->ratesProvider->method('getRate')
            ->with('USD', 'EUR', 1)
            ->willReturn(0.9);

        $result = $this->model->getMinimumOrderVisibility($this->quote('EUR', 'USD'));

        $this->assertFalse($result['unresolved']);
        $this->assertSame(
            [['amount' => 250.0, 'basis' => 'net'], ['amount' => 450.0, 'basis' => 'net']],
            $result['minimums']
        );
    }
}
