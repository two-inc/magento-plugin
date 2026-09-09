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
use Two\Gateway\Api\Log\RepositoryInterface as LogRepository;
use Two\Gateway\Model\Two;
use Two\Gateway\Service\Merchant\ApiKeyStatus;
use Two\Gateway\Service\Merchant\SupportedCountriesProvider;
use Two\Gateway\Service\Order\BuyerCountryResolver;
use Two\Gateway\Service\Order\MinimumOrderGate;
use Two\Gateway\Service\Order\MerchantMinimumResolver;
use Two\Gateway\Service\Order\MinimumOrderProvider;
use Two\Gateway\Service\Order\SurchargeCalculator;

/**
 * ABN-546: a buyer fee quote the pricing service could not answer withholds
 * this payment method at checkout, silently and without re-quoting.
 */
class TwoFeeQuoteGateTest extends TestCase
{
    /** @var LogRepository|\PHPUnit\Framework\MockObject\MockObject */
    private $logRepository;

    private function build(SurchargeCalculator $surchargeCalculator): Two
    {
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

        $this->logRepository = $this->createMock(LogRepository::class);

        $properties = [
            '_scopeConfig' => $scopeConfig,
            'apiKeyStatus' => $apiKeyStatus,
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
        ];
        foreach ($properties as $name => $value) {
            if ($reflection->hasProperty($name)) {
                $reflection->getProperty($name)->setValue($model, $value);
            }
        }

        return $model;
    }

    /**
     * A concrete currency, so the gate reaches the calculator instead of conceding.
     */
    private function makeQuote(string $currency): Quote
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
        return $quote;
    }

    /**
     * Given a fee-quote verdict for the charged term; when the payment-method
     * list renders; then the method is offered or withheld with one debug line.
     *
     * @dataProvider feeQuoteVerdicts
     */
    public function testTheFeeQuoteVerdictDecidesAvailability(
        bool|string $verdict,
        string $currency,
        bool $expectedAvailable,
        ?string $expectedReason,
        string $case
    ): void {
        $calculator = $this->createMock(SurchargeCalculator::class);
        $calculator->method('isSurchargeResolvable')->willReturn(true);
        if ($verdict === 'throw') {
            $calculator->method('hasFailedFeeQuote')->willThrowException(
                // Plain string: __() here would mint a phrase for collect-phrases.
                new LocalizedException(new Phrase('refused'))
            );
        } else {
            $calculator->method('hasFailedFeeQuote')->willReturn($verdict);
        }
        $model = $this->build($calculator);

        // The pricing service already reported the cause where it happened.
        $this->logRepository->expects($this->never())->method('addErrorLog');
        $debug = [];
        $this->logRepository->method('addDebugLog')->willReturnCallback(
            function ($type) use (&$debug): void {
                $debug[] = (string)$type;
            }
        );

        $this->assertSame(
            $expectedAvailable,
            $model->isAvailable($this->makeQuote($currency)),
            $case
        );
        if ($expectedReason === null) {
            $this->assertSame([], $debug, 'nothing to report: ' . $case);
            return;
        }
        $this->assertCount(1, $debug, 'one debug line saying why: ' . $case);
        $this->assertStringContainsString($expectedReason, $debug[0], $case);
    }

    public function feeQuoteVerdicts(): array
    {
        return [
            [true, 'EUR', false, 'buyer fee quote failed', 'the charged term cannot be priced'],
            ['throw', 'EUR', false, 'buyer fee quote unreadable', 'the verdict itself could not be reached'],
            [false, 'EUR', true, null, 'the quote answered, so nothing is withheld'],
            [true, '', true, null, 'no currency to judge a quote by'],
        ];
    }
}
