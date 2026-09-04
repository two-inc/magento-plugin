<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Service\Order;

use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address;
use Magento\Store\Model\Store;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Service\Order\BuyerCountryResolver;

/**
 * The buyer country a country-restricted payment method is judged on:
 * billing first, then shipping, then the store default.
 */
class BuyerCountryResolverTest extends TestCase
{
    /**
     * @dataProvider resolutionCases
     */
    public function testCountryResolvesInPrecedenceOrder(
        ?string $billing,
        ?string $shipping,
        ?string $storeDefault,
        string $expected,
        string $case
    ): void {
        $quote = $this->createMock(Quote::class);
        $quote->method('getBillingAddress')->willReturn($this->address($billing));
        $quote->method('getShippingAddress')->willReturn($this->address($shipping));

        $store = $this->createMock(Store::class);
        $store->method('getConfig')->willReturn($storeDefault);
        $quote->method('getStore')->willReturn($store);

        $this->assertSame($expected, (new BuyerCountryResolver())->resolve($quote), $case);
    }

    /**
     * @return array<string, array{0: ?string, 1: ?string, 2: ?string, 3: string, 4: string}>
     */
    public static function resolutionCases(): array
    {
        return [
            'billing present' =>
                ['GB', 'NO', 'SE', 'GB', 'billing wins over shipping'],
            'billing address missing' =>
                [null, 'NO', 'SE', 'NO', 'shipping used when there is no billing address'],
            'billing country blank' =>
                ['', 'NO', 'SE', 'NO', 'an addressless billing record falls through to shipping'],
            'neither address' =>
                [null, null, 'SE', 'SE', 'store default used when the quote carries no address'],
            'nothing at all' =>
                [null, null, null, '', 'unresolvable country reported as empty, never guessed'],
            'shipping only' =>
                [null, 'DE', null, 'DE', 'shipping alone is enough'],
        ];
    }

    public function testANonQuoteCartCannotBeJudged(): void
    {
        // Totals collection and the admin order flow both reach isAvailable()
        // with no concrete quote; the gate must skip rather than guess.
        $this->assertSame('', (new BuyerCountryResolver())->resolve(null));
    }

    private function address(?string $country): ?Address
    {
        if ($country === null) {
            return null;
        }
        $address = $this->createMock(Address::class);
        $address->method('getCountryId')->willReturn($country);
        return $address;
    }
}
