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
use Two\Gateway\Service\Order\FeeQuoteGate;
use Two\Gateway\Service\Order\MerchantMinimumResolver;
use Two\Gateway\Service\Order\MinimumOrderGate;
use Two\Gateway\Service\Order\MinimumOrderProvider;
use Two\Gateway\Service\Order\SurchargeCalculator;
use Two\Gateway\Test\Unit\Service\Order\Doubles\FixedVerdictFeeQuoteGate;

/**
 * ABN-546: the fee-quote verdict reaches isAvailable(), and a withhold is
 * silent apart from one debug line. FeeQuoteGateTest owns the verdict itself.
 */
class TwoFeeQuoteGateTest extends TestCase
{
    /** @var LogRepository|\PHPUnit\Framework\MockObject\MockObject */
    private $logRepository;

    /**
     * Given a fee-quote verdict; when the payment-method list renders; then
     * the method follows it and says why once.
     *
     * @dataProvider verdicts
     */
    public function testTheFeeQuoteVerdictReachesAvailability(
        bool $quotable,
        bool $expectedAvailable,
        ?string $expectedReason,
        string $case
    ): void {
        $model = $this->build($quotable);

        // The pricing service already reported the cause where it happened.
        $this->logRepository->expects($this->never())->method('addErrorLog');
        $debug = [];
        $this->logRepository->method('addDebugLog')->willReturnCallback(
            function ($type) use (&$debug): void {
                $debug[] = (string)$type;
            }
        );

        $this->assertSame($expectedAvailable, $model->isAvailable($this->makeQuote()), $case);
        if ($expectedReason === null) {
            $this->assertSame([], $debug, 'nothing to report: ' . $case);
            return;
        }
        $this->assertCount(1, $debug, 'one debug line saying why: ' . $case);
        $this->assertStringContainsString($expectedReason, $debug[0], $case);
    }

    public function verdicts(): array
    {
        return [
            [false, false, 'buyer fee quote failed', 'the fee cannot be priced'],
            [true, true, null, 'the fee can be priced'],
        ];
    }

    private function build(bool $quotable): Two
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

        $surchargeCalculator = $this->createMock(SurchargeCalculator::class);
        $surchargeCalculator->method('isSurchargeResolvable')->willReturn(true);

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
            'feeQuoteGate' => new FixedVerdictFeeQuoteGate($quotable),
        ];
        foreach ($properties as $name => $value) {
            $reflection->getProperty($name)->setValue($model, $value);
        }

        return $model;
    }

    private function makeQuote(): Quote
    {
        $address = $this->createMock(Address::class);
        $address->method('getCountryId')->willReturn('NO');

        $store = $this->createMock(Store::class);
        $store->method('getId')->willReturn(1);
        $store->method('getBaseCurrencyCode')->willReturn('EUR');

        $quote = $this->createMock(Quote::class);
        $quote->method('getBillingAddress')->willReturn($address);
        $quote->method('getStore')->willReturn($store);
        $quote->method('getStoreId')->willReturn(1);
        $quote->method('getQuoteCurrencyCode')->willReturn('EUR');
        return $quote;
    }
}
