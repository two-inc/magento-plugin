<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Service\Order;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Area;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\State as AppState;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address;
use Magento\Store\Model\Store;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\Config\RepositoryInterface as ConfigRepository;
use Two\Gateway\Api\CurrencyRatesProviderInterface;
use Two\Gateway\Api\Log\RepositoryInterface as LogRepository;
use Two\Gateway\Model\Config\Source\SurchargeType;
use Two\Gateway\Service\Api\Adapter;
use Two\Gateway\Service\Merchant\SettingsProvider;
use Two\Gateway\Service\Merchant\SurchargeCapProvider;
use Two\Gateway\Service\Order\BuyerCountryResolver;
use Two\Gateway\Service\Order\ChargedTermResolver;
use Two\Gateway\Service\Order\FeeQuoteGate;
use Two\Gateway\Test\Unit\Service\Order\Doubles\RecordingAdapter;
use Two\Gateway\Test\Unit\Service\Order\Doubles\RecordingSurchargeCalculator;

/**
 * ABN-546. The gate prices the charged term on the request being judged;
 * anything short of a refusal concedes the method.
 */
class FeeQuoteGateTest extends TestCase
{
    private RecordingAdapter $adapter;

    private RecordingSurchargeCalculator $calculator;

    /** @var list<array{0: string, 1: array<string, mixed>}> */
    private array $gateErrors = [];

    private CheckoutSession $session;

    /**
     * Given a checkout state and a pricing outcome; when availability is
     * judged; then the method is conceded or withheld, and the endpoint is
     * called only where there is something to price.
     *
     * @dataProvider gateScenarios
     */
    public function testTheGatePricesOnlyWhatThereIsToPrice(
        string $area,
        string $surchargeType,
        int $itemCount,
        float $grandTotal,
        float $feeAlreadyOnQuote,
        string $currency,
        int $offeredTerm,
        bool $upstreamRefuses,
        bool $expectApiCall,
        bool $expectQuotable,
        string $case
    ): void {
        $gate = $this->buildGate(
            $area,
            $surchargeType,
            $offeredTerm,
            $upstreamRefuses ? ['http_status' => 503, 'error_code' => 'UPSTREAM'] : ['buyer_fee_share' => 12.5]
        );
        $this->session->setTwoSurchargeGross($feeAlreadyOnQuote);

        $this->assertSame(
            $expectQuotable,
            $gate->isQuotable($this->makeQuote($grandTotal, $itemCount, $currency), 1),
            $case
        );
        $this->assertSame(
            $expectApiCall ? 1 : 0,
            $this->calculator->attempts,
            'quote attempts: ' . $case
        );
        $this->assertCount(
            $expectApiCall ? 1 : 0,
            $this->adapter->calls,
            'pricing calls: ' . $case
        );
        if ($expectApiCall) {
            $this->assertSame(
                $grandTotal - $feeAlreadyOnQuote,
                $this->adapter->calls[0]['payload']['gross_amount'],
                'the fee already on the quote is not priced again: ' . $case
            );
            $this->assertSame(
                $offeredTerm,
                $this->adapter->calls[0]['payload']['order_terms']['duration_days'],
                'the charged term is the one quoted: ' . $case
            );
        }
    }

    public function gateScenarios(): array
    {
        return [
            [
                Area::AREA_ADMINHTML, SurchargeType::PERCENTAGE, 1, 1000.0, 0.0, 'EUR', 30,
                false, false, true, 'an admin path never prices a buyer fee',
            ],
            [
                Area::AREA_FRONTEND, SurchargeType::NONE, 1, 1000.0, 0.0, 'EUR', 30,
                false, false, true, 'no surcharge is configured',
            ],
            [
                Area::AREA_FRONTEND, SurchargeType::PERCENTAGE, 0, 1000.0, 0.0, 'EUR', 30,
                false, false, true, 'the basket has no items',
            ],
            [
                Area::AREA_FRONTEND, SurchargeType::PERCENTAGE, 1, 100.0, 100.0, 'EUR', 30,
                false, false, true, 'the whole total is the fee already on the quote',
            ],
            [
                Area::AREA_FRONTEND, SurchargeType::PERCENTAGE, 1, 1000.0, 0.0, '', 30,
                false, false, true, 'there is no currency to price in',
            ],
            [
                Area::AREA_FRONTEND, SurchargeType::PERCENTAGE, 1, 1000.0, 0.0, 'EUR', 0,
                false, false, true, 'no term is offered',
            ],
            [
                Area::AREA_FRONTEND, SurchargeType::PERCENTAGE, 1, 1250.0, 250.0, 'EUR', 30,
                false, true, true, 'the endpoint answers, on the fee-exclusive total',
            ],
            [
                Area::AREA_FRONTEND, SurchargeType::PERCENTAGE, 1, 1000.0, 0.0, 'EUR', 30,
                true, true, false, 'the endpoint refuses the quote',
            ],
        ];
    }

    public function testTheRenderPathQuoteCarriesTheSurchargePricingCeiling(): void
    {
        // Given the payment list is rendering; when the fee is quoted; then the
        // call cannot fall through to the adapter's default while it hangs.
        $gate = $this->buildGate(
            Area::AREA_FRONTEND,
            SurchargeType::PERCENTAGE,
            30,
            ['buyer_fee_share' => 12.5]
        );

        $gate->isQuotable($this->makeQuote(1000.0, 1, 'EUR'), 1);

        // Read rather than restated, so the bound holds if either number moves.
        $adapterDefault = (new \ReflectionClass(Adapter::class))
            ->getConstant('DEFAULT_TIMEOUT_SECONDS');
        $timeout = $this->adapter->calls[0]['timeout'];
        $this->assertNotNull($timeout, 'the quote is bounded, not left to the adapter default');
        $this->assertGreaterThan(0, $timeout, 'a timeout of zero would never time out');
        $this->assertLessThan(
            $adapterDefault,
            $timeout,
            'a surcharge quote is bounded tighter than the adapter default'
        );
    }

    public function testAMalformedCachedQuoteWithholdsTheMethodRatherThanBreakingThePage(): void
    {
        // Given a cached quote that is not valid JSON; when availability is
        // judged; then only this method is lost, not the whole render.
        $gate = $this->buildGate(
            Area::AREA_FRONTEND,
            SurchargeType::PERCENTAGE,
            30,
            ['buyer_fee_share' => 12.5],
            '{ this is not json'
        );

        $this->assertFalse(
            $gate->isQuotable($this->makeQuote(1000.0, 1, 'EUR'), 1),
            'a malformed cached quote withholds the method'
        );
    }

    /**
     * Given a withhold; when it happens; then the cause is on the record at a
     * level someone will see, and carries nothing identifying.
     *
     * @dataProvider withholdCauses
     */
    public function testAWithholdRecordsItsCause(
        array $response,
        string|bool $cached,
        string $expectedClass,
        string $case
    ): void {
        $gate = $this->buildGate(
            Area::AREA_FRONTEND,
            SurchargeType::PERCENTAGE,
            30,
            $response,
            $cached
        );

        $this->assertFalse($gate->isQuotable($this->makeQuote(1000.0, 1, 'EUR'), 1), $case);
        $this->assertCount(1, $this->gateErrors, 'one error line naming the cause: ' . $case);
        [$message, $data] = $this->gateErrors[0];
        $this->assertStringContainsString('withheld', $message, $case);
        $this->assertSame($expectedClass, $data['error'] ?? null, $case);
        $this->assertNotSame('', (string)($data['reason'] ?? ''), 'the cause is not blank: ' . $case);
        $this->assertSame(
            ['error', 'reason'],
            array_keys($data),
            'nothing about the cart, the buyer or the merchant is logged: ' . $case
        );
    }

    public function withholdCauses(): array
    {
        return [
            [
                ['http_status' => 503, 'error_code' => 'UPSTREAM'],
                false,
                LocalizedException::class,
                'the endpoint refused the quote',
            ],
            [
                ['buyer_fee_share' => 12.5],
                '{ this is not json',
                \InvalidArgumentException::class,
                'the cached quote is corrupt',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $response
     */
    private function buildGate(
        string $area,
        string $surchargeType,
        int $offeredTerm,
        array $response,
        string|bool $cached = false
    ): FeeQuoteGate {
        $config = $this->createMock(ConfigRepository::class);
        $config->method('getSurchargeType')->willReturn($surchargeType);
        $config->method('getSurchargeFixedCurrency')->willReturn('');
        $config->method('getSurchargeConfig')->willReturn(
            ['percentage' => 2.0, 'fixed' => 0.0, 'limit' => null]
        );
        $config->method('isSurchargeDifferential')->willReturn(false);
        $config->method('getPaymentTermsType')->willReturn('standard');
        $config->method('getSurchargeLineDescription')->willReturn('Payment terms fee');
        $config->method('getCustomSurchargeTaxRate')->willReturn(0.0);
        $config->method('getDefaultPaymentTerm')->willReturn($offeredTerm > 0 ? $offeredTerm : null);
        $config->method('isBuyerTermAvailable')->willReturn($offeredTerm > 0);

        $this->adapter = new RecordingAdapter($response);
        $this->session = new CheckoutSession();

        $cache = $this->createMock(CacheInterface::class);
        $cache->method('load')->willReturn($cached);

        $this->calculator = new RecordingSurchargeCalculator(
            $config,
            $this->adapter,
            $this->createMock(LogRepository::class),
            $this->createMock(CurrencyRatesProviderInterface::class),
            $cache,
            new Json(),
            new SurchargeCapProvider(
                $this->getMockBuilder(SettingsProvider::class)->disableOriginalConstructor()->getMock(),
                $this->createMock(CurrencyRatesProviderInterface::class)
            )
        );

        $appState = new AppState();
        $appState->setAreaCode($area);

        return new FeeQuoteGate(
            $appState,
            $config,
            new ChargedTermResolver($this->session, $config),
            $this->session,
            $this->calculator,
            new BuyerCountryResolver(),
            $this->recordingLog()
        );
    }

    /**
     * @return LogRepository|\PHPUnit\Framework\MockObject\MockObject
     */
    private function recordingLog()
    {
        $this->gateErrors = [];
        $log = $this->createMock(LogRepository::class);
        $log->method('addErrorLog')->willReturnCallback(
            function ($message, $data = []) {
                $this->gateErrors[] = [(string)$message, (array)$data];
            }
        );
        return $log;
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
