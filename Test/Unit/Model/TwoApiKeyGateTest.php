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
 * The payment method must not be offered when the stored API key does not
 * currently verify — whatever the reason it does not.
 *
 * A configured api_key used to be treated as a working one, so a revoked
 * key or an unreachable API left the method selectable and the buyer met
 * the failure at order placement instead of at selection.
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

        $properties = [
            '_scopeConfig' => $scopeConfig,
            'apiKeyStatus' => $apiKeyStatus,
            'logRepository' => $this->createMock(LogRepository::class),
            'minimumOrderProvider' => $this->createMock(MinimumOrderProvider::class),
            'minimumOrderGate' => $minimumOrderGate,
            'amastyCheckoutStore' => [],
            'buyerCountryResolver' => new BuyerCountryResolver(),
            'supportedCountriesProvider' => $this->createMock(SupportedCountriesProvider::class),
        ];
        foreach ($properties as $name => $value) {
            $reflection->getProperty($name)->setValue($model, $value);
        }

        return $model;
    }

    private function statusService(string $status, ?int $code = null): ApiKeyStatus
    {
        $service = $this->createMock(ApiKeyStatus::class);
        $service->method('isVerified')->willReturn($status === ApiKeyStatus::OK);
        $service->method('getStatus')->willReturn(
            ['status' => $status, 'code' => $code, 'merchant' => null]
        );
        return $service;
    }

    /**
     * @dataProvider failureCategories
     */
    public function testMethodIsUnavailableForEveryVerificationFailure(string $status, ?int $code): void
    {
        $model = $this->build($this->statusService($status, $code));

        $this->assertFalse($model->isAvailable(null));
    }

    /**
     * @return array<string, array{0: string, 1: int|null}>
     */
    public static function failureCategories(): array
    {
        return [
            'rejected key' => [ApiKeyStatus::INVALID_KEY, 401],
            'service error' => [ApiKeyStatus::SERVICE_ERROR, 503],
            'unreachable' => [ApiKeyStatus::UNREACHABLE, null],
            'other error' => [ApiKeyStatus::ERROR, 404],
            'malformed response' => [ApiKeyStatus::MALFORMED_RESPONSE, null],
            'not configured' => [ApiKeyStatus::NOT_CONFIGURED, null],
        ];
    }

    public function testMethodIsAvailableWhenTheKeyVerifies(): void
    {
        $model = $this->build($this->statusService(ApiKeyStatus::OK, 200));

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

    public function testFailureIsLoggedWithTheCategoryButNoResponseBody(): void
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
        $model = $this->build($this->statusService(ApiKeyStatus::SERVICE_ERROR, 503));
        $reflection->getProperty('logRepository')->setValue($model, $logRepository);

        $this->assertFalse($model->isAvailable(null));

        $this->assertCount(1, $logged);
        $this->assertStringContainsString('API key verification failed', $logged[0][0]);
        $this->assertSame(
            ['status' => ApiKeyStatus::SERVICE_ERROR, 'http_status' => 503],
            $logged[0][1]
        );
    }
}
