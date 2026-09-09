<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address;
use Magento\Store\Model\Store;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\Config\RepositoryInterface as ConfigRepository;
use Two\Gateway\Api\Log\RepositoryInterface as LogRepository;
use Two\Gateway\Model\Config\Source\SurchargeType;
use Two\Gateway\Model\Two;
use Two\Gateway\Service\Merchant\ApiKeyStatus;
use Two\Gateway\Service\Merchant\SupportedCountriesProvider;
use Two\Gateway\Service\Order\BuyerCountryResolver;
use Two\Gateway\Service\Order\ChargedTermResolver;
use Two\Gateway\Service\Order\MerchantMinimumResolver;
use Two\Gateway\Service\Order\MinimumOrderGate;
use Two\Gateway\Service\Order\MinimumOrderProvider;
use Two\Gateway\Service\Order\SurchargeCalculator;

/**
 * ABN-546: the payment method is withheld when the fee for the term the
 * checkout would be charged for cannot be priced, judged on the request
 * that renders the payment-method list.
 */
class TwoFeeQuoteGateTest extends TestCase
{
    /** @var LogRepository|\PHPUnit\Framework\MockObject\MockObject */
    private $logRepository;

    /**
     * Given a cart and a pricing outcome; when the payment-method list
     * renders; then the method is offered or withheld, and a pricing call is
     * made only where there is something to price.
     *
     * @dataProvider feeQuoteScenarios
     */
    public function testTheFeeQuoteDecidesAvailability(
        string $surchargeType,
        float $grandTotal,
        int $itemCount,
        string $currency,
        int $chargedTerm,
        bool $quoteRefused,
        bool $expectPricingCall,
        bool $expectedAvailable,
        ?string $expectedReason,
        string $case
    ): void {
        $calls = [];
        $calculator = $this->createMock(SurchargeCalculator::class);
        $calculator->method('isSurchargeResolvable')->willReturn(true);
        $calculator->method('calculate')->willReturnCallback(
            function (...$args) use (&$calls, $quoteRefused): array {
                $calls[] = $args;
                if ($quoteRefused) {
                    // Plain string: __() here would mint a phrase for collect-phrases.
                    throw new LocalizedException(new Phrase('pricing refused'));
                }
                return ['amount' => 12.5, 'tax_rate' => 0.0, 'description' => 'fee'];
            }
        );

        $model = $this->build($calculator, $surchargeType, $chargedTerm);

        // The pricing service already reported the cause where it happened.
        $this->logRepository->expects($this->never())->method('addErrorLog');
        $debug = [];
        $this->logRepository->method('addDebugLog')->willReturnCallback(
            function ($type) use (&$debug): void {
                $debug[] = (string)$type;
            }
        );

        $available = $model->isAvailable($this->makeQuote($grandTotal, $itemCount, $currency));

        $this->assertSame($expectedAvailable, $available, $case);
        $this->assertCount(
            $expectPricingCall ? 1 : 0,
            $calls,
            'pricing calls: ' . $case
        );
        if ($expectPricingCall) {
            $this->assertSame(
                [$grandTotal, $chargedTerm, 'NO', $currency, 1],
                $calls[0],
                'the quote asks for the charged term on this cart: ' . $case
            );
        }
        if ($expectedReason === null) {
            $this->assertSame([], $debug, 'nothing to report: ' . $case);
            return;
        }
        $this->assertCount(1, $debug, 'one debug line saying why: ' . $case);
        $this->assertStringContainsString($expectedReason, $debug[0], $case);
    }

    public function feeQuoteScenarios(): array
    {
        return [
            [
                SurchargeType::PERCENTAGE, 1000.0, 1, 'EUR', 30, true, true, false,
                'buyer fee quote failed', 'the charged term cannot be priced',
            ],
            [
                SurchargeType::PERCENTAGE, 1000.0, 1, 'EUR', 30, false, true, true,
                null, 'the quote answers, so the method is offered',
            ],
            [
                SurchargeType::NONE, 1000.0, 1, 'EUR', 30, false, false, true,
                null, 'no surcharge is configured, so there is no fee to price',
            ],
            [
                SurchargeType::PERCENTAGE, 0.0, 1, 'EUR', 30, false, false, true,
                null, 'the basket total is not positive',
            ],
            [
                SurchargeType::PERCENTAGE, 1000.0, 0, 'EUR', 30, false, false, true,
                null, 'the basket has no items',
            ],
            [
                SurchargeType::PERCENTAGE, 1000.0, 1, '', 30, false, false, true,
                null, 'there is no currency to price in',
            ],
            [
                SurchargeType::PERCENTAGE, 1000.0, 1, 'EUR', 0, false, false, true,
                null, 'no term is offered, so none is charged',
            ],
        ];
    }

    private function build(
        SurchargeCalculator $surchargeCalculator,
        string $surchargeType,
        int $chargedTerm
    ): Two {
        $reflection = new \ReflectionClass(Two::class);
        $model = $reflection->newInstanceWithoutConstructor();

        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturn('test-api-key');

        $apiKeyStatus = $this->createMock(ApiKeyStatus::class);
        $apiKeyStatus->method('isDefinitiveFailure')->willReturn(false);

        $minimumOrderGate = $this->createMock(MinimumOrderGate::class);
        $minimumOrderGate->method('isSatisfied')->willReturn(true);

        $countriesProvider = $this->createMock(SupportedCountriesProvider::class);
        $countriesProvider->method('isAllowed')->willReturn(true);

        $configRepository = $this->createMock(ConfigRepository::class);
        $configRepository->method('getSurchargeType')->willReturn($surchargeType);

        $termResolver = $this->createMock(ChargedTermResolver::class);
        $termResolver->method('resolve')->willReturn($chargedTerm);

        $this->logRepository = $this->createMock(LogRepository::class);

        $properties = [
            '_scopeConfig' => $scopeConfig,
            'apiKeyStatus' => $apiKeyStatus,
            'configRepository' => $configRepository,
            'logRepository' => $this->logRepository,
            'minimumOrderProvider' => $this->createMock(MinimumOrderProvider::class),
            'minimumOrderGate' => $minimumOrderGate,
            'merchantMinimumResolver' => $this->createMock(MerchantMinimumResolver::class),
            // Memoized false: the Amasty bypass is another gate's subject.
            'amastyCheckoutStore' => [1 => false],
            'stubConfigData' => [],
            'buyerCountryResolver' => new BuyerCountryResolver(),
            'supportedCountriesProvider' => $countriesProvider,
            'surchargeCalculator' => $surchargeCalculator,
            'chargedTermResolver' => $termResolver,
        ];
        foreach ($properties as $name => $value) {
            $reflection->getProperty($name)->setValue($model, $value);
        }

        return $model;
    }

    private function makeQuote(float $grandTotal, int $itemCount, string $currency): Quote
    {
        $address = $this->createMock(Address::class);
        $address->method('getCountryId')->willReturn('NO');

        $store = $this->createMock(Store::class);
        $store->method('getId')->willReturn(1);
        $store->method('getBaseCurrencyCode')->willReturn($currency);

        $quote = $this->createMock(Quote::class);
        $quote->method('getBillingAddress')->willReturn($address);
        $quote->method('getStore')->willReturn($store);
        $quote->method('getStoreId')->willReturn(1);
        $quote->method('getQuoteCurrencyCode')->willReturn($currency);
        $quote->method('getGrandTotal')->willReturn($grandTotal);
        $quote->method('getAllVisibleItems')->willReturn(
            array_fill(0, $itemCount, new \stdClass())
        );
        return $quote;
    }
}
