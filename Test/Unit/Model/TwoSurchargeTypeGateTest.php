<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;
use Magento\Quote\Model\Quote;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\Log\RepositoryInterface as LogRepository;
use Two\Gateway\Model\Two;
use Two\Gateway\Service\Merchant\ApiKeyStatus;
use Two\Gateway\Service\Merchant\SupportedCountriesProvider;
use Two\Gateway\Service\Order\BuyerCountryResolver;
use Two\Gateway\Service\Order\MinimumOrderGate;
use Two\Gateway\Service\Order\MinimumOrderProvider;
use Two\Gateway\Service\Order\SurchargeCalculator;

/**
 * A corrupt stored surcharge method withdraws THIS payment method and
 * nothing else. Raising out of isAvailable() empties the whole payment-method
 * list and breaks admin order create, so the refusal is caught here and
 * re-asserted at placement instead.
 */
class TwoSurchargeTypeGateTest extends TestCase
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
            'merchantMinimumResolver' => null,
            'amastyCheckoutStore' => [],
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

    private function makeQuote(): Quote
    {
        return new class extends Quote {
            public function getStoreId()
            {
                return 1;
            }

            public function getStore()
            {
                return new class extends DataObject {
                    public function getBaseCurrencyCode()
                    {
                        return 'EUR';
                    }
                };
            }

            public function getQuoteCurrencyCode()
            {
                return 'EUR';
            }

            public function getBillingAddress()
            {
                return new DataObject(['countryId' => 'NO']);
            }

            public function getShippingAddress()
            {
                return new DataObject(['countryId' => 'NO']);
            }
        };
    }

    /**
     * @dataProvider corruptStoredMethods
     */
    public function testACorruptStoredMethodWithdrawsOnlyThisMethod(string $stored, string $case): void
    {
        $calculator = $this->createMock(SurchargeCalculator::class);
        $calculator->method('isSurchargeResolvable')->willThrowException(
            // Plain string: __() here would mint a phrase for collect-phrases.
            new LocalizedException(new Phrase('refused: ' . $stored))
        );
        $model = $this->build($calculator);

        // The config repository is the one place that reports this, so the
        // gate must withdraw silently rather than emit a second error line.
        $this->logRepository->expects($this->never())->method('addErrorLog');
        $debug = [];
        $this->logRepository->method('addDebugLog')->willReturnCallback(
            function ($type) use (&$debug): void {
                $debug[] = (string)$type;
            }
        );

        $this->assertFalse($model->isAvailable($this->makeQuote()), $case);
        $this->assertCount(1, $debug, 'one debug line saying why: ' . $case);
        $this->assertStringContainsString('unrecognised surcharge method', $debug[0], $case);
    }

    public function corruptStoredMethods(): array
    {
        return [
            ['wat', 'junk from a hand-edited row or an import'],
            ['PERCENTAGE', 'the right method in the wrong case'],
            ['0', 'a falsy value a truthiness check would have read as unset'],
        ];
    }
}
