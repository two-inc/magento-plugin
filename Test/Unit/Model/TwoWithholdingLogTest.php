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
use Two\Gateway\Service\Merchant\SettingsProvider;
use Two\Gateway\Service\Merchant\SupportedCountriesProvider;
use Two\Gateway\Service\Order\BuyerCountryResolver;
use Two\Gateway\Service\Order\MerchantMinimumResolver;
use Two\Gateway\Service\Order\MinimumOrderGate;
use Two\Gateway\Service\Order\MinimumOrderProvider;
use Two\Gateway\Service\Order\SurchargeCalculator;

/**
 * TWO-25641: every branch that withholds the method names its own reason, so one
 * shared line at the exit cannot diagnose several causes at once.
 */
class TwoWithholdingLogTest extends TestCase
{
    /**
     * @dataProvider withholdingCases
     */
    public function testEachWithholdingBranchNamesItsOwnReason(
        string $knob,
        bool $expectedAvailable,
        ?string $expectedReason,
        string $description
    ): void {
        $logged = [];
        $model = $this->build($knob, $logged);

        $this->assertSame($expectedAvailable, $model->isAvailable($this->quote()), $description);

        $reasons = array_values(array_filter(
            array_column($logged, 0),
            static fn(string $m): bool => str_contains($m, 'hidden from checkout')
        ));

        if ($expectedReason === null) {
            $this->assertSame([], $reasons, $description);
            return;
        }
        $this->assertCount(1, $reasons, $description);
        $this->assertSame('two_payment hidden from checkout: ' . $expectedReason, $reasons[0], $description);
    }

    /**
     * @return list<array{0: string, 1: bool, 2: ?string, 3: string}>
     */
    public static function withholdingCases(): array
    {
        return [
            ['core_refuses', false, 'core payment-method checks failed',
                'core\'s own verdict is reported as core\'s, never asserted as "inactive"'],
            ['no_api_key', false, 'no API key configured',
                'an unconfigured key is named rather than withheld silently'],
            ['key_unverified', false, 'API key verification failed',
                'a configured but non-working key is distinguishable from an absent one'],
            ['surcharge_unresolvable', false, 'surcharge FX rate unavailable',
                'an unresolvable surcharge rate is named at the render that withheld the method'],
            ['country_refused', false, 'buyer country not supported',
                'the country gate names itself'],
            ['below_minimum', false, null,
                'the gate logs from inside itself; isAvailable() must not add a second, vaguer line'],
            ['all_pass', true, null,
                'nothing withheld, nothing logged'],
        ];
    }

    /**
     * @param list<array{0: string, 1: mixed}> $logged captured by reference
     */
    private function build(string $knob, array &$logged): Two
    {
        $reflection = new \ReflectionClass(Two::class);
        $model = $reflection->newInstanceWithoutConstructor();

        $logRepository = $this->createMock(LogRepository::class);
        $logRepository->method('addDebugLog')->willReturnCallback(
            function ($message, $data = null) use (&$logged) {
                $logged[] = [$message, $data];
            }
        );

        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(
            static fn(string $path) => str_ends_with($path, '/api_key') && $knob === 'no_api_key'
                ? ''
                : 'configured'
        );

        $apiKeyStatus = $this->createMock(ApiKeyStatus::class);
        $apiKeyStatus->method('isVerified')->willReturn($knob !== 'key_unverified');
        $apiKeyStatus->method('getStatus')->willReturn(['status' => 'rejected', 'code' => 401]);

        $surchargeCalculator = $this->createMock(SurchargeCalculator::class);
        $surchargeCalculator->method('isSurchargeResolvable')
            ->willReturn($knob !== 'surcharge_unresolvable');

        $gate = $this->createMock(MinimumOrderGate::class);
        $gate->method('isSatisfied')->willReturn($knob !== 'below_minimum');

        $properties = [
            '_scopeConfig' => $scopeConfig,
            'stubAvailableInBase' => $knob !== 'core_refuses',
            'logRepository' => $logRepository,
            'apiKeyStatus' => $apiKeyStatus,
            'settingsProvider' => $this->offeredTermsProvider(),
            'surchargeCalculator' => $surchargeCalculator,
            'minimumOrderGate' => $gate,
            'minimumOrderProvider' => $this->createMock(MinimumOrderProvider::class),
            'merchantMinimumResolver' => $this->createMock(MerchantMinimumResolver::class),
            'buyerCountryResolver' => new BuyerCountryResolver(),
            'supportedCountriesProvider' => $this->countriesProvider($knob),
            'amastyCheckoutStore' => [1 => false],
            'stubConfigData' => [],
        ];
        foreach ($properties as $name => $value) {
            $reflection->getProperty($name)->setValue($model, $value);
        }

        return $model;
    }

    private function countriesProvider(string $knob): SupportedCountriesProvider
    {
        $provider = $this->createMock(SupportedCountriesProvider::class);
        $provider->method('isAllowed')->willReturn($knob !== 'country_refused');
        $provider->method('getState')->willReturn(SupportedCountriesProvider::STATE_ALLOWLIST);
        return $provider;
    }

    /**
     * A concrete currency, so the surcharge gate reaches the calculator instead of conceding.
     */
    private function quote(): Quote
    {
        $address = $this->createMock(Address::class);
        $address->method('getCountryId')->willReturn('GB');

        $store = $this->createMock(Store::class);
        $store->method('getId')->willReturn(1);
        $store->method('getBaseCurrencyCode')->willReturn('GBP');

        $quote = $this->createMock(Quote::class);
        $quote->method('getBillingAddress')->willReturn($address);
        $quote->method('getStore')->willReturn($store);
        $quote->method('getStoreId')->willReturn(1);
        $quote->method('getQuoteCurrencyCode')->willReturn('GBP');
        return $quote;
    }

    /**
     * A resolvable merchant record — without one the method is withheld
     * before the gate under test is reached (ABN-493).
     */
    private function offeredTermsProvider(): SettingsProvider
    {
        $provider = $this->createMock(SettingsProvider::class);
        $provider->method('getAvailableTerms')->willReturn([14, 30]);
        return $provider;
    }
}
