<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Model;

use Magento\Directory\Model\Currency;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Model\Order;
use Magento\Store\Model\Store;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\BrandRegistryInterface;
use Two\Gateway\Api\CurrencyRatesProviderInterface;
use Two\Gateway\Api\Log\RepositoryInterface as LogRepository;
use Two\Gateway\Model\Two;
use Two\Gateway\Service\Order\MerchantMinimumResolver;
use Two\Gateway\Service\Order\MinimumOrderGate;
use Two\Gateway\Service\Order\MinimumOrderProvider;

/**
 * Tests for Two::assertOrderMeetsMinimum()'s split fail policy at order
 * placement: an unprojectable PLATFORM floor rejects the order (fail-closed),
 * while an unprojectable MERCHANT minimum is skipped and placement proceeds
 * (fail-open). A projectable-but-unmet merchant minimum still rejects —
 * fail-open applies only to the missing-FX case, not to "below the bar".
 */
class TwoAssertOrderMeetsMinimumTest extends TestCase
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

        $brandRegistry = $this->createMock(BrandRegistryInterface::class);
        $brandRegistry->method('getProductName')->willReturn('Two');

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

        // buildMerchantMinimum() now delegates to MerchantMinimumResolver;
        // back it with a scope-config stub reading the same $configData the
        // model's own getConfigData() override reads, so tests keep driving
        // the admin-config values through one property.
        $model = $this->model;
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(
            static function (string $path) use ($model) {
                $field = substr($path, strrpos($path, '/') + 1);
                return $model->configData[$field] ?? null;
            }
        );
        $merchantMinimumResolver = new MerchantMinimumResolver($scopeConfig);

        $ref = new \ReflectionClass(Two::class);
        $injected = [
            'minimumOrderGate' => $gate,
            'minimumOrderProvider' => $this->minimumOrderProvider,
            'brandRegistry' => $brandRegistry,
            'merchantMinimumResolver' => $merchantMinimumResolver,
        ];
        foreach ($injected as $name => $value) {
            $ref->getProperty($name)->setValue($this->model, $value);
        }
    }

    private function order(string $orderCurrency, string $baseCurrency, float $grandTotal, float $taxAmount = 0.0): Order
    {
        $store = $this->createMock(Store::class);
        $store->method('getBaseCurrencyCode')->willReturn($baseCurrency);

        // The Currency stub is a method-less catch-all; give it a formatTxt.
        $currency = new class ($orderCurrency) extends Currency {
            public function __construct(private string $code)
            {
            }

            public function formatTxt($amount): string
            {
                return sprintf('%s %.2f', $this->code, (float)$amount);
            }
        };

        // The stub Order is a faithful DataObject: magic getters read the bag.
        $order = new Order();
        $order->setData('store_id', 1);
        $order->setData('order_currency_code', $orderCurrency);
        $order->setData('store', $store);
        $order->setData('grand_total', $grandTotal);
        $order->setData('tax_amount', $taxAmount);
        $order->setData('order_currency', $currency);
        return $order;
    }

    private function assertOrderMeetsMinimum(Order $order): void
    {
        $method = new \ReflectionMethod(Two::class, 'assertOrderMeetsMinimum');
        $method->invoke($this->model, $order);
    }

    public function testUnprojectablePlatformFloorRejectsOrder(): void
    {
        // Platform floor in EUR, order in SEK, no EUR->SEK rate: fail closed —
        // the placement backstop must reject rather than waive the floor.
        $this->minimumOrderProvider->method('getMinimum')
            ->willReturn(['amount' => 250.0, 'currency' => 'EUR', 'basis' => 'net']);
        $this->ratesProvider->method('getRate')->willReturn(null);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('not available for this order');

        $this->assertOrderMeetsMinimum($this->order('SEK', 'SEK', 10000.0));
    }

    public function testUnprojectableMerchantMinimumIsSkippedAndOrderProceeds(): void
    {
        // Platform floor in the order currency (projects without FX) and
        // satisfied; the merchant minimum is denominated in the base currency
        // (USD) with no USD->EUR rate. Fail open: the merchant bar is skipped
        // and placement proceeds.
        $this->minimumOrderProvider->method('getMinimum')
            ->willReturn(['amount' => 250.0, 'currency' => 'EUR', 'basis' => 'net']);
        $this->model->configData = [
            'merchant_minimum_order' => 500.0,
            'merchant_minimum_order_basis' => 'net',
        ];
        $this->ratesProvider->method('getRate')
            ->with('USD', 'EUR', 1)
            ->willReturn(null);

        $this->assertOrderMeetsMinimum($this->order('EUR', 'USD', 300.0));

        $this->addToAssertionCount(1); // no exception: order placement proceeds
    }

    public function testNanRatePlatformFloorRejectsOrder(): void
    {
        // NAN <= 0 is false in PHP: without the finiteness guard in the
        // display projection, the platform floor would come back as
        // ['amount' => NAN] (non-null), the below-minimum comparison against
        // NaN would always be false, and the sole placement backstop would
        // silently admit an order it could not verify. Fail closed instead.
        $this->minimumOrderProvider->method('getMinimum')
            ->willReturn(['amount' => 250.0, 'currency' => 'EUR', 'basis' => 'net']);
        $this->ratesProvider->method('getRate')->willReturn(NAN);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('not available for this order');

        $this->assertOrderMeetsMinimum($this->order('SEK', 'SEK', 10000.0));
    }

    public function testNanRateMerchantMinimumIsSkippedAndOrderProceeds(): void
    {
        // NaN on the merchant bar's rate fails open, same as a missing rate:
        // the bar is skipped and placement proceeds.
        $this->minimumOrderProvider->method('getMinimum')
            ->willReturn(['amount' => 250.0, 'currency' => 'EUR', 'basis' => 'net']);
        $this->model->configData = [
            'merchant_minimum_order' => 500.0,
            'merchant_minimum_order_basis' => 'net',
        ];
        $this->ratesProvider->method('getRate')
            ->with('USD', 'EUR', 1)
            ->willReturn(NAN);

        $this->assertOrderMeetsMinimum($this->order('EUR', 'USD', 300.0));

        $this->addToAssertionCount(1); // no exception: order placement proceeds
    }

    public function testProjectableButUnmetMerchantMinimumStillRejects(): void
    {
        // Fail-open covers ONLY the missing-FX case: with a usable USD->EUR
        // rate the merchant bar projects to 450 EUR and a 300 EUR order is
        // still rejected.
        $this->minimumOrderProvider->method('getMinimum')
            ->willReturn(['amount' => 250.0, 'currency' => 'EUR', 'basis' => 'net']);
        $this->model->configData = [
            'merchant_minimum_order' => 500.0,
            'merchant_minimum_order_basis' => 'net',
        ];
        $this->ratesProvider->method('getRate')
            ->with('USD', 'EUR', 1)
            ->willReturn(0.9);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Minimum order value');

        $this->assertOrderMeetsMinimum($this->order('EUR', 'USD', 300.0));
    }
}
