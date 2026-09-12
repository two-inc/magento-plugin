<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Model\Config\Comment;

use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\CurrencyRatesProviderInterface;
use Two\Gateway\Model\Config\Comment\MerchantMinimumOrder;
use Two\Gateway\Service\Order\MinimumOrderProvider;

/**
 * The admin help text under "Minimum order value". Three distinct cases, each
 * of which has to say something different about what the merchant may type:
 * no platform floor at all, a floor comparable with the base currency, and a
 * floor in another currency with no rate to compare it by (TWO-25503).
 */
class MerchantMinimumOrderTest extends TestCase
{
    /**
     * @param array{amount: float, currency: string, basis: string}|null $platformMinimum
     */
    private function comment(?array $platformMinimum, string $baseCurrency, ?float $rate): string
    {
        $model = (new \ReflectionClass(MerchantMinimumOrder::class))->newInstanceWithoutConstructor();

        $provider = $this->createMock(MinimumOrderProvider::class);
        $provider->method('getMinimum')->willReturn($platformMinimum);

        $rates = $this->createMock(CurrencyRatesProviderInterface::class);
        $rates->method('getRate')->willReturn($rate);

        // Hand-rolled: the bootstrap stubs these Magento contracts as empty
        // interfaces, so PHPUnit has no methods to configure on them.
        $store = new class ($baseCurrency) {
            private $baseCurrency;

            public function __construct(string $baseCurrency)
            {
                $this->baseCurrency = $baseCurrency;
            }

            public function getId()
            {
                return 1;
            }

            public function getBaseCurrencyCode()
            {
                return $this->baseCurrency;
            }
        };
        $storeManager = new class ($store) {
            private $store;

            public function __construct($store)
            {
                $this->store = $store;
            }

            public function getStore($storeId = null)
            {
                return $this->store;
            }
        };
        $priceCurrency = new class {
            public function format($amount, $includeContainer = true, $precision = 2, $scope = null, $currency = null)
            {
                return sprintf('%s %.2f', $currency, $amount);
            }
        };
        $request = new class {
            public function getParam($key, $default = null)
            {
                return null;
            }
        };

        $inject = static function (string $property, $value) use ($model): void {
            $reflected = new \ReflectionProperty(MerchantMinimumOrder::class, $property);
            $reflected->setAccessible(true);
            $reflected->setValue($model, $value);
        };
        $inject('minimumOrderProvider', $provider);
        $inject('ratesProvider', $rates);
        $inject('storeManager', $storeManager);
        $inject('priceCurrency', $priceCurrency);
        $inject('request', $request);

        return $model->getCommentText('');
    }

    public function testNoPlatformMinimumJustExplainsTheField(): void
    {
        $text = $this->comment(null, 'GBP', null);

        $this->assertStringContainsString('Leave empty for no minimum', $text);
        $this->assertStringNotContainsString('Platform minimum', $text);
    }

    public function testASameCurrencyFloorIsStatedAsATargetToMeet(): void
    {
        $text = $this->comment(['amount' => 250.0, 'currency' => 'GBP', 'basis' => 'net'], 'GBP', null);

        $this->assertStringContainsString('Platform minimum GBP 250.00', $text);
        $this->assertStringContainsString('must be at least this', $text);
        $this->assertStringContainsString('excluding tax', $text);
    }

    public function testAConvertibleFloorShowsBothCurrencies(): void
    {
        $text = $this->comment(['amount' => 250.0, 'currency' => 'EUR', 'basis' => 'gross'], 'GBP', 0.86);

        $this->assertStringContainsString('GBP 215.00 (EUR 250.00)', $text);
        $this->assertStringContainsString('must be at least this', $text);
        $this->assertStringContainsString('including tax', $text);
    }

    /**
     * The third variant. With no rate the floor can only be shown in ITS
     * currency, which the field is not interpreted in — so "must be at least
     * this" would name a number the merchant cannot type, and the save-time
     * validation cannot compare the two either.
     */
    public function testAnUnconvertibleFloorNamesTheGapInsteadOfAskingForAComparison(): void
    {
        $text = $this->comment(['amount' => 250.0, 'currency' => 'EUR', 'basis' => 'net'], 'GBP', null);

        $this->assertStringContainsString('Platform minimum EUR 250.00', $text);
        $this->assertStringContainsString('no exchange rate for EUR to GBP', $text);
        $this->assertStringContainsString('cannot be compared', $text);
        $this->assertStringNotContainsString('must be at least this', $text);
    }

    /**
     * A rate of 0 is as useless as none: it would render the floor as 0.00 in
     * the base currency and invite any value at all.
     */
    public function testAZeroRateIsTreatedAsNoRate(): void
    {
        $text = $this->comment(['amount' => 250.0, 'currency' => 'EUR', 'basis' => 'net'], 'GBP', 0.0);

        $this->assertStringContainsString('cannot be compared', $text);
    }
}
