<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Service\Merchant;

use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\CurrencyRatesProviderInterface;
use Two\Gateway\Service\Merchant\SettingsProvider;
use Two\Gateway\Service\Merchant\SurchargeCapProvider;

/**
 * The merchant's fixed-surcharge cap, resolved into the currency a caller
 * compares against.
 *
 * `exact` is the load-bearing part: the admin form compares against an
 * unconverted cap quite happily, because doing so can only refuse more than the
 * real cap would, while a path that charges a buyer must not accept a ceiling
 * it cannot express in the currency being charged.
 */
class SurchargeCapProviderTest extends TestCase
{
    /**
     * @param array<string, mixed>|null $limit the merchant record's cap tuple
     * @param array<string, mixed>|null $expected
     *
     * @dataProvider capCases
     */
    public function testTheCapIsResolvedIntoTheTargetCurrency(
        ?array $limit,
        string $targetCurrency,
        ?float $rate,
        ?array $expected,
        string $case
    ): void {
        $settings = $this->getMockBuilder(SettingsProvider::class)
            ->disableOriginalConstructor()
            ->getMock();
        $settings->method('getSurchargeLimit')->willReturn($limit);

        $rates = $this->createMock(CurrencyRatesProviderInterface::class);
        $rates->method('getRate')->willReturn($rate);

        $this->assertSame(
            $expected,
            (new SurchargeCapProvider($settings, $rates))->inCurrency($targetCurrency, 1),
            $case
        );
    }

    /**
     * @return array<int, array<int, mixed>>
     */
    public static function capCases(): array
    {
        return [
            [null, 'EUR', null, null, 'no cap on the merchant record means no ceiling'],
            [
                ['amount' => 25.0, 'currency' => 'EUR'],
                'EUR',
                null,
                ['amount' => 25, 'exact' => true],
                'a cap already in the target currency needs no conversion',
            ],
            [
                ['amount' => 25.9, 'currency' => 'EUR'],
                'EUR',
                null,
                ['amount' => 25, 'exact' => true],
                'the cap is truncated to whole units, never widened to 26',
            ],
            [
                ['amount' => 25.0, 'currency' => 'EUR'],
                'SEK',
                10.5,
                ['amount' => 263, 'exact' => true],
                'a converted cap rounds up, so the merchant keeps the whole of it',
            ],
            [
                ['amount' => 25.0, 'currency' => 'EUR'],
                'SEK',
                null,
                ['amount' => 25, 'exact' => false],
                'a cap no rate converts is reported inexact, not silently applied',
            ],
            [
                ['amount' => 25.0, 'currency' => 'EUR'],
                'SEK',
                0.0,
                ['amount' => 25, 'exact' => false],
                'a non-positive rate is no rate',
            ],
            [
                ['amount' => 25.0, 'currency' => 'EUR'],
                '',
                10.5,
                ['amount' => 25, 'exact' => false],
                'an unknown target currency gives nothing to compare against',
            ],
        ];
    }
}
