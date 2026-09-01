<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address;
use Magento\Store\Model\Store;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\Log\RepositoryInterface as LogRepository;
use Two\Gateway\Model\Two;
use Two\Gateway\Service\Merchant\ApiKeyStatus;
use Two\Gateway\Service\Merchant\SupportedCountriesProvider;
use Two\Gateway\Service\Order\BuyerCountryResolver;
use Two\Gateway\Service\Order\MerchantMinimumResolver;
use Two\Gateway\Service\Order\MinimumOrderGate;
use Two\Gateway\Service\Order\MinimumOrderProvider;

/**
 * TWO-40: the method is withdrawn when the buyer's country is outside the
 * merchant's server-supplied allowlist.
 *
 * The allowlist is a second gate ANDed with core's own admin-configured
 * allowspecific/specificcountry gate, which still runs first — neither
 * replaces the other, and either alone can withdraw the method.
 */
class TwoCountryGateTest extends TestCase
{
    /**
     * @dataProvider gateCases
     */
    public function testBothCountryGatesMustPass(
        bool $coreAllowsGb,
        bool $merchantAllowsGb,
        bool $expected,
        string $case
    ): void {
        $model = $this->build(
            $this->countriesProvider($merchantAllowsGb ? ['GB'] : ['NO']),
            $coreAllowsGb ? null : 'NO'
        );

        $this->assertSame($expected, $model->isAvailable($this->quote('GB')), $case);
    }

    /**
     * @return array<string, array{0: bool, 1: bool, 2: bool, 3: string}>
     */
    public static function gateCases(): array
    {
        return [
            'both allow' => [true, true, true, 'method offered only when both gates concede'],
            'core refuses' => [false, true, false, 'the admin country restriction still applies'],
            'merchant refuses' => [true, false, false, 'the merchant allowlist withdraws the method'],
            'both refuse' => [false, false, false, 'no country passes either gate'],
        ];
    }

    /**
     * @dataProvider countrySourceCases
     */
    public function testTheGateJudgesBillingThenShippingCountry(
        ?string $billing,
        ?string $shipping,
        ?string $storeDefault,
        bool $expected,
        string $case
    ): void {
        // Only GB is allowed, so the verdict reveals which address the gate read.
        $model = $this->build($this->countriesProvider(['GB']));

        $quote = $this->createMock(Quote::class);
        $quote->method('getBillingAddress')->willReturn($this->address($billing));
        $quote->method('getShippingAddress')->willReturn($this->address($shipping));
        $store = $this->createMock(Store::class);
        $store->method('getConfig')->willReturn($storeDefault);
        $quote->method('getStore')->willReturn($store);

        $this->assertSame($expected, $model->isAvailable($quote), $case);
    }

    /**
     * @return array<string, array{0: ?string, 1: ?string, 2: ?string, 3: bool, 4: string}>
     */
    public static function countrySourceCases(): array
    {
        return [
            'billing allowed' =>
                ['GB', 'NO', null, true, 'billing country judged, not shipping'],
            'billing refused' =>
                ['NO', 'GB', null, false, 'a disallowed billing country is not rescued by shipping'],
            'billing absent, shipping allowed' =>
                [null, 'GB', null, true, 'shipping country judged when no billing address exists'],
            'billing absent, shipping refused' =>
                [null, 'NO', null, false, 'shipping country judged when no billing address exists'],
            'neither address, store default refused' =>
                [null, null, 'NO', false, 'store default judged as a last resort'],
            'no country resolvable' =>
                [null, null, null, false, 'a restricted merchant withholds rather than guess'],
        ];
    }

    public function testWithdrawalIsLoggedWithTheCountry(): void
    {
        $logged = [];
        $logRepository = $this->createMock(LogRepository::class);
        $logRepository->method('addDebugLog')->willReturnCallback(
            function ($message, $data = null) use (&$logged) {
                $logged[] = [$message, $data];
            }
        );

        $model = $this->build($this->countriesProvider(['GB']));
        (new \ReflectionClass(Two::class))
            ->getProperty('logRepository')
            ->setValue($model, $logRepository);

        $this->assertFalse($model->isAvailable($this->quote('DE')));

        $this->assertCount(1, $logged);
        $this->assertStringContainsString('buyer country not supported', $logged[0][0]);
        $this->assertSame(
            ['country' => 'DE', 'restriction' => SupportedCountriesProvider::STATE_ALLOWLIST],
            $logged[0][1]
        );
    }

    /**
     * The other side of the unjudgeable-quote branch: withholding is the
     * restricted merchant's answer, not everyone's.
     */
    public function testAnUnrestrictedMerchantIsStillOfferedWhenNoCountryResolves(): void
    {
        $model = $this->build($this->countriesProvider(null));

        $quote = $this->createMock(Quote::class);
        $quote->method('getBillingAddress')->willReturn(null);
        $quote->method('getShippingAddress')->willReturn(null);
        $quote->method('getStore')->willReturn($this->createMock(Store::class));

        $this->assertTrue($model->isAvailable($quote));
    }

    /**
     * Builds a Two instance holding only the collaborators isAvailable() and
     * canUseForCountry() reach; the real constructor needs the full
     * payment-method framework graph, which these gates do not touch.
     */
    private function build(
        SupportedCountriesProvider $countriesProvider,
        ?string $coreSpecificCountry = null
    ): Two {
        $reflection = new \ReflectionClass(Two::class);
        $model = $reflection->newInstanceWithoutConstructor();

        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturn('test-api-key');

        $apiKeyStatus = $this->createMock(ApiKeyStatus::class);
        $apiKeyStatus->method('isVerified')->willReturn(true);

        $minimumOrderGate = $this->createMock(MinimumOrderGate::class);
        $minimumOrderGate->method('isSatisfied')->willReturn(true);

        $properties = [
            '_scopeConfig' => $scopeConfig,
            'apiKeyStatus' => $apiKeyStatus,
            'logRepository' => $this->createMock(LogRepository::class),
            'minimumOrderProvider' => $this->createMock(MinimumOrderProvider::class),
            'merchantMinimumResolver' => $this->createMock(MerchantMinimumResolver::class),
            'minimumOrderGate' => $minimumOrderGate,
            'amastyCheckoutStore' => [],
            'buyerCountryResolver' => new BuyerCountryResolver(),
            'supportedCountriesProvider' => $countriesProvider,
            'stubConfigData' => $coreSpecificCountry === null
                ? []
                : ['allowspecific' => 1, 'specificcountry' => $coreSpecificCountry],
        ];
        foreach ($properties as $name => $value) {
            $reflection->getProperty($name)->setValue($model, $value);
        }

        return $model;
    }

    /**
     * @param list<string>|null $allowed null for a merchant that restricts nothing.
     */
    private function countriesProvider(?array $allowed): SupportedCountriesProvider
    {
        $provider = $this->createMock(SupportedCountriesProvider::class);
        $provider->method('isAllowed')->willReturnCallback(
            static fn(string $country, ?int $storeId = null): bool
                => $allowed === null || in_array(strtoupper(trim($country)), $allowed, true)
        );
        $provider->method('getState')->willReturn(
            $allowed === null
                ? SupportedCountriesProvider::STATE_UNRESTRICTED
                : SupportedCountriesProvider::STATE_ALLOWLIST
        );
        return $provider;
    }

    private function quote(string $billingCountry): Quote
    {
        $quote = $this->createMock(Quote::class);
        $quote->method('getBillingAddress')->willReturn($this->address($billingCountry));
        $quote->method('getStore')->willReturn($this->createMock(Store::class));
        return $quote;
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
