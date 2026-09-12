<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Model;

use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Model\Order;
use Magento\Store\Model\Store;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\BrandRegistryInterface;
use Two\Gateway\Api\Log\RepositoryInterface as LogRepository;
use Two\Gateway\Model\Two;
use Two\Gateway\Service\Merchant\ApiKeyStatus;
use Two\Gateway\Service\Merchant\SupportedCountriesProvider;
use Two\Gateway\Service\Order\BuyerCountryResolver;

/**
 * Placement backstop for the buyer-country gate. The display gate withdraws
 * the method at render; a hidden method can still be submitted, so the
 * allowlist is enforced again on the finalised order.
 */
class TwoBuyerCountrySubmitGateTest extends TestCase
{
    private const MSGID = 'Invoice purchase with %1 is not available for this order.';

    /** @var array<int,array{0: string, 1: mixed}> */
    private $logged = [];

    /**
     * @param array<string,mixed>|null $record
     * @dataProvider submitCases
     */
    public function testTheGateRefusesUnlessTheRecordAllowsTheOrderCountry(
        ?array $record,
        ?string $billing,
        ?string $shipping,
        ?string $storeDefault,
        bool $accepted,
        string $case
    ): void {
        $model = $this->build($record);
        $order = $this->order($billing, $shipping, $storeDefault);

        if ($accepted) {
            $this->invokeGuard($model, $order);
            $this->addToAssertionCount(1);
            $this->assertSame([], $this->logged, $case);
            return;
        }

        try {
            $this->invokeGuard($model, $order);
            $this->fail('Expected the gate to refuse: ' . $case);
        } catch (LocalizedException $e) {
            $this->assertSame(str_replace('%1', 'Two', self::MSGID), $e->getMessage(), $case);
        }
    }

    /**
     * @return array<string, array{
     *     0: array<string,mixed>|null, 1: ?string, 2: ?string, 3: ?string, 4: bool, 5: string
     * }>
     */
    public static function submitCases(): array
    {
        return [
            'field absent' =>
                [['min_order_amount' => 100], 'DE', null, null, true,
                    'an old API carries no allowlist, so nothing is restricted'],
            'no record at all' =>
                [null, 'DE', null, null, true,
                    'a failed merchant fetch must not block placement'],
            'field null' =>
                [['supported_buyer_countries' => null], 'GB', null, null, false,
                    'a present null admits no country'],
            'field empty' =>
                [['supported_buyer_countries' => []], 'GB', null, null, false,
                    'a present empty list admits no country'],
            'field malformed' =>
                [['supported_buyer_countries' => 'GB'], 'GB', null, null, false,
                    'a malformed value is refused, not parsed'],
            'listed billing country' =>
                [['supported_buyer_countries' => ['GB']], 'GB', null, null, true,
                    'the billing country is judged'],
            'unlisted billing country' =>
                [['supported_buyer_countries' => ['GB']], 'DE', 'GB', null, false,
                    'a refused billing country is not rescued by shipping'],
            'shipping country when no billing address' =>
                [['supported_buyer_countries' => ['GB']], null, 'GB', null, true,
                    'shipping is judged when the order carries no billing address'],
            'store default as last resort' =>
                [['supported_buyer_countries' => ['GB']], null, null, 'GB', true,
                    'the store default is judged when neither address has a country'],
            'restricted and no country resolvable' =>
                [['supported_buyer_countries' => ['GB']], null, null, null, false,
                    'a restricted merchant withholds rather than guess'],
            'unrestricted and no country resolvable' =>
                [['min_order_amount' => 100], null, null, null, true,
                    'an unjudgeable order is only refused when the merchant restricts'],
        ];
    }

    /**
     * @dataProvider loggedStateCases
     */
    public function testEveryRefusalIsLoggedWithTheStateThatCausedIt(
        mixed $fieldValue,
        string $expectedState,
        string $case
    ): void {
        $model = $this->build(['supported_buyer_countries' => $fieldValue]);

        try {
            $this->invokeGuard($model, $this->order('DE', null, null));
            $this->fail('Expected the gate to refuse: ' . $case);
        } catch (LocalizedException $e) {
            // The refusal is the subject of the sibling test; this pins the log.
        }

        $this->assertCount(1, $this->logged, $case);
        $this->assertStringContainsString('buyer country not supported', $this->logged[0][0], $case);
        $this->assertSame(
            ['merchant_id' => 'merchant-uuid', 'country' => 'DE', 'restriction' => $expectedState],
            $this->logged[0][1],
            $case
        );
    }

    /**
     * @return array<string, array{0: mixed, 1: string, 2: string}>
     */
    public static function loggedStateCases(): array
    {
        return [
            'present null' =>
                [null, SupportedCountriesProvider::STATE_EMPTY, 'a present null logs as empty'],
            'present empty' =>
                [[], SupportedCountriesProvider::STATE_EMPTY, 'a present empty list logs as empty'],
            'malformed' =>
                ['GB', SupportedCountriesProvider::STATE_MALFORMED, 'a malformed value logs distinctly'],
            'unlisted country' =>
                [['GB'], SupportedCountriesProvider::STATE_ALLOWLIST, 'a real allowlist logs as such'],
        ];
    }

    /**
     * The gate is worthless unless authorize() calls it, and calls it before
     * the order request is composed. authorize() needs the full framework to
     * execute, so pin the wiring by reading the method's own source.
     */
    public function testAuthorizeInvokesTheGateBeforeComposingTheRequest(): void
    {
        $method = new \ReflectionMethod(Two::class, 'authorize');
        $source = implode('', array_slice(
            file($method->getFileName()),
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1
        ));

        $guardAt = strpos($source, 'assertBuyerCountrySupported(');
        $composeAt = strpos($source, 'compositeOrder->execute(');

        $this->assertNotFalse($guardAt, 'authorize() must call the buyer-country gate.');
        $this->assertSame(
            1,
            preg_match_all('/assertBuyerCountrySupported\(([^)]*)\)/', $source, $calls),
            'authorize() must call the buyer-country gate exactly once.'
        );
        $this->assertSame('$order', $calls[1][0], 'The gate must be handed the order being placed.');
        $this->assertNotFalse($composeAt, 'authorize() must still compose the order request.');
        $this->assertLessThan($composeAt, $guardAt, 'The gate must run before the request is composed.');
    }

    private function invokeGuard(Two $model, Order $order): void
    {
        (new \ReflectionMethod(Two::class, 'assertBuyerCountrySupported'))->invoke($model, $order);
    }

    /**
     * @param array<string,mixed>|null $record
     */
    private function build(?array $record): Two
    {
        $reflection = new \ReflectionClass(Two::class);
        $model = $reflection->newInstanceWithoutConstructor();

        $brandRegistry = $this->createMock(BrandRegistryInterface::class);
        $brandRegistry->method('getProductName')->willReturn('Two');

        $apiKeyStatus = $this->createMock(ApiKeyStatus::class);
        $apiKeyStatus->method('getStatus')->willReturn([
            'status' => ApiKeyStatus::OK,
            'code' => 200,
            'merchant' => ['id' => 'merchant-uuid'],
        ]);

        $logRepository = $this->createMock(LogRepository::class);
        $logRepository->method('addLog')->willReturnCallback(
            function ($message, $data = null) {
                $this->logged[] = [$message, $data];
                return null;
            }
        );

        // Real provider over a mocked record fetch: the tri-state derivation
        // under test is the shipped one, not a mock's.
        $recordProvider = $this->createMock(\Two\Gateway\Service\Merchant\RecordProvider::class);
        $recordProvider->method('getRecord')->willReturn($record);

        $properties = [
            'brandRegistry' => $brandRegistry,
            'apiKeyStatus' => $apiKeyStatus,
            'logRepository' => $logRepository,
            'buyerCountryResolver' => new BuyerCountryResolver(),
            'supportedCountriesProvider' => new SupportedCountriesProvider($recordProvider),
        ];
        foreach ($properties as $name => $value) {
            $reflection->getProperty($name)->setValue($model, $value);
        }

        return $model;
    }

    private function order(?string $billing, ?string $shipping, ?string $storeDefault): Order
    {
        $store = $this->createMock(Store::class);
        $store->method('getConfig')->willReturn($storeDefault);

        // The stub Order is a faithful DataObject: magic getters read the bag.
        $order = new Order();
        $order->setData('store_id', 1);
        $order->setData('store', $store);
        $order->setData('billing_address', $this->address($billing));
        $order->setData('shipping_address', $this->address($shipping));
        return $order;
    }

    private function address(?string $country): ?object
    {
        if ($country === null) {
            return null;
        }
        return new class ($country) {
            public function __construct(private string $country)
            {
            }

            public function getCountryId(): string
            {
                return $this->country;
            }
        };
    }
}
