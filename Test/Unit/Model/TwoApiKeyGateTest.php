<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\Log\RepositoryInterface as LogRepository;
use Two\Gateway\Model\Two;
use Two\Gateway\Service\Merchant\ApiKeyStatus;
use Two\Gateway\Service\Merchant\SupportedCountriesProvider;
use Two\Gateway\Service\Order\BuyerCountryResolver;
use Two\Gateway\Service\Order\MinimumOrderGate;
use Two\Gateway\Service\Order\MinimumOrderProvider;

/**
 * The api-key verdict is the only upstream failure that may withhold the
 * payment method, and only when it is a DEFINITIVE rejection: Two said no,
 * or there is no key. Everything else falls through to the cached merchant
 * record (ABN-533).
 */
class TwoApiKeyGateTest extends TestCase
{
    /**
     * Builds a Two instance with only the collaborators isAvailable()
     * reaches, injected by reflection. The real constructor needs the full
     * payment-method framework graph, which this gate does not touch.
     */
    private function build(ApiKeyStatus $apiKeyStatus, bool $minimumSatisfied = true): Two
    {
        $reflection = new \ReflectionClass(Two::class);
        $model = $reflection->newInstanceWithoutConstructor();

        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        // A key IS stored — the point of this gate is that storing one is not
        // the same as it working.
        $scopeConfig->method('getValue')->willReturn('test-api-key');

        $minimumOrderGate = $this->createMock(MinimumOrderGate::class);
        $minimumOrderGate->method('isSatisfied')->willReturn($minimumSatisfied);

        // Unrestricted: the buyer-country gate has its own tests and must not
        // decide the verdict here.
        $countriesProvider = $this->createMock(SupportedCountriesProvider::class);
        $countriesProvider->method('isAllowed')->willReturn(true);

        $properties = [
            '_scopeConfig' => $scopeConfig,
            'apiKeyStatus' => $apiKeyStatus,
            'logRepository' => $this->createMock(LogRepository::class),
            'minimumOrderProvider' => $this->createMock(MinimumOrderProvider::class),
            'minimumOrderGate' => $minimumOrderGate,
            'amastyCheckoutStore' => [],
            'buyerCountryResolver' => new BuyerCountryResolver(),
            'supportedCountriesProvider' => $countriesProvider,
        ];
        foreach ($properties as $name => $value) {
            $reflection->getProperty($name)->setValue($model, $value);
        }

        return $model;
    }

    /**
     * The real ApiKeyStatus over a stubbed verdict — only getStatus() is
     * overridden — so the gate runs the production predicate and cannot pass
     * by re-stating the rule in the test.
     */
    private function statusService(string $status, ?int $code = null): ApiKeyStatus
    {
        return new class (['status' => $status, 'code' => $code, 'merchant' => null]) extends ApiKeyStatus {
            /** @var array{status: string, code: int|null, merchant: array<string,mixed>|null} */
            private $verdict;

            /** @param array{status: string, code: int|null, merchant: array<string,mixed>|null} $verdict */
            public function __construct(array $verdict)
            {
                $this->verdict = $verdict;
            }

            public function getStatus(?int $storeId = null, ?string $scope = null): array
            {
                return $this->verdict;
            }
        };
    }

    /**
     * @dataProvider verdictCategories
     */
    public function testOnlyADefinitiveRejectionWithholdsTheMethod(
        string $status,
        ?int $code,
        bool $offered,
        string $description
    ): void {
        $model = $this->build($this->statusService($status, $code));

        $this->assertSame($offered, $model->isAvailable(null), $description);
    }

    /**
     * @return array<string, array{0: string, 1: int|null, 2: bool, 3: string}>
     */
    public static function verdictCategories(): array
    {
        return [
            'ok' => [ApiKeyStatus::OK, 200, true,
                'a verifying key is offered'],
            'invalid key' => [ApiKeyStatus::INVALID_KEY, 401, false,
                'Two rejected the key, so the integration cannot work'],
            'not configured' => [ApiKeyStatus::NOT_CONFIGURED, null, false,
                'there is no key to serve an order with'],
            'service error' => [ApiKeyStatus::SERVICE_ERROR, 503, true,
                'a 5xx says nothing about the key; the cached record still serves the buyer'],
            'unreachable' => [ApiKeyStatus::UNREACHABLE, null, true,
                'an outage must not empty a correctly configured checkout'],
            'other error' => [ApiKeyStatus::ERROR, 404, true,
                'a non-2xx that is not a 401/403 is not a rejection of the key'],
            'malformed response' => [ApiKeyStatus::MALFORMED_RESPONSE, null, true,
                'an unparseable answer is an answer about the service, not the key'],
        ];
    }

    public function testAnOutageIsNotLoggedAsAWithhold(): void
    {
        // The method is still offered, so there is nothing to explain.
        $logRepository = $this->createMock(LogRepository::class);
        $logRepository->expects($this->never())->method('addDebugLog');

        $model = $this->build($this->statusService(ApiKeyStatus::UNREACHABLE, null));
        (new \ReflectionClass(Two::class))
            ->getProperty('logRepository')->setValue($model, $logRepository);

        $this->assertTrue($model->isAvailable(null));
    }

    public function testTheGateDoesNotOverrideOtherReasonsToHide(): void
    {
        // A verifying key does not make the method available on its own — the
        // minimum-order gate still decides. Pins that the new check was added
        // as an extra condition rather than as a short-circuit.
        $model = $this->build($this->statusService(ApiKeyStatus::OK, 200), false);

        $this->assertFalse($model->isAvailable(null));
    }

    public function testRejectionIsLoggedWithTheCategoryButNoResponseBody(): void
    {
        // Withdrawing the method is invisible to the merchant, so the reason
        // has to be recorded — and recorded as a category, not a payload.
        $logRepository = $this->createMock(LogRepository::class);
        $logged = [];
        $logRepository->method('addDebugLog')->willReturnCallback(
            function ($message, $data = null) use (&$logged) {
                $logged[] = [$message, $data];
            }
        );

        $reflection = new \ReflectionClass(Two::class);
        $model = $this->build($this->statusService(ApiKeyStatus::INVALID_KEY, 401));
        $reflection->getProperty('logRepository')->setValue($model, $logRepository);

        $this->assertFalse($model->isAvailable(null));

        $this->assertCount(1, $logged);
        $this->assertStringContainsString('API key rejected', $logged[0][0]);
        $this->assertSame(
            ['status' => ApiKeyStatus::INVALID_KEY, 'http_status' => 401],
            $logged[0][1]
        );
    }

}
