<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\Log\RepositoryInterface as LogRepository;
use Two\Gateway\Model\Two;
use Two\Gateway\Service\Merchant\ApiKeyStatus;
use Two\Gateway\Service\Merchant\RecordProvider;
use Two\Gateway\Service\Merchant\SupportedCountriesProvider;
use Two\Gateway\Service\Order\BuyerCountryResolver;
use Two\Gateway\Service\Order\MinimumOrderGate;
use Two\Gateway\Service\Order\MinimumOrderProvider;

/**
 * A merchant-record fetch that fails says nothing about whether the API key
 * works, so it must not take the payment method off the storefront. The
 * api-key verification verdict is the only upstream failure that withholds
 * (ABN-519).
 */
class TwoMerchantRecordFailureTest extends TestCase
{
    /**
     * Builds a Two instance with only the collaborators isAvailable() reaches,
     * injected by reflection. The minimum-order provider is the REAL one over
     * a record provider that cannot resolve, so the record failure reaches the
     * availability chain the way it does in production.
     *
     * @param array<string,mixed>|null $record
     */
    private function build(?array $record): Two
    {
        $reflection = new \ReflectionClass(Two::class);
        $model = $reflection->newInstanceWithoutConstructor();

        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturn('test-api-key');

        $apiKeyStatus = $this->createMock(ApiKeyStatus::class);
        $apiKeyStatus->method('isVerified')->willReturn(true);
        $apiKeyStatus->method('getStatus')->willReturn(
            ['status' => ApiKeyStatus::OK, 'code' => 200, 'merchant' => ['id' => 'abc-123']]
        );

        $recordProvider = $this->createMock(RecordProvider::class);
        $recordProvider->method('getRecord')->willReturn($record);

        $minimumOrderGate = $this->createMock(MinimumOrderGate::class);
        $minimumOrderGate->method('isSatisfied')->willReturn(true);

        $countriesProvider = $this->createMock(SupportedCountriesProvider::class);
        $countriesProvider->method('isAllowed')->willReturn(true);

        $properties = [
            '_scopeConfig' => $scopeConfig,
            'apiKeyStatus' => $apiKeyStatus,
            'logRepository' => $this->createMock(LogRepository::class),
            'minimumOrderProvider' => new MinimumOrderProvider($recordProvider),
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
     * @param array<string,mixed>|null $record
     * @dataProvider recordStates
     */
    public function testTheMethodIsOfferedWhateverTheRecordFetchDid(?array $record, string $description): void
    {
        $this->assertTrue($this->build($record)->isAvailable(null), $description);
    }

    /**
     * @return array<int, array{0: array<string,mixed>|null, 1: string}>
     */
    public static function recordStates(): array
    {
        return [
            [null, 'an unresolvable record — a 5xx, a timeout, an unreachable host — withholds nothing'],
            [['id' => 'abc-123'], 'a record carrying no terms and no minimum withholds nothing'],
            [['id' => 'abc-123', 'available_terms' => [14, 30]], 'a resolved record offers the method'],
        ];
    }

    public function testTheRecordFetchIsNotAReasonToLogAWithholding(): void
    {
        $logged = [];
        $logRepository = $this->createMock(LogRepository::class);
        $logRepository->method('addDebugLog')->willReturnCallback(
            function ($message, $data = null) use (&$logged) {
                $logged[] = $message;
            }
        );

        $model = $this->build(null);
        (new \ReflectionClass(Two::class))->getProperty('logRepository')->setValue($model, $logRepository);

        $this->assertTrue($model->isAvailable(null));
        $this->assertSame([], preg_grep('/hidden from checkout/', $logged));
    }
}
