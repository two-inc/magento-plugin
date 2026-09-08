<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\Log\RepositoryInterface as LogRepository;
use Two\Gateway\Model\Two;
use Two\Gateway\Service\Merchant\ApiKeyStatus;
use Two\Gateway\Service\Merchant\SettingsProvider;
use Two\Gateway\Service\Merchant\SupportedCountriesProvider;
use Two\Gateway\Service\Order\BuyerCountryResolver;
use Two\Gateway\Service\Order\MinimumOrderGate;
use Two\Gateway\Service\Order\MinimumOrderProvider;

/**
 * The payment method must not be offered while the merchant record cannot be
 * reached: without it there is no set of terms the buyer may be offered, and
 * anything the admin has stored is unvalidated (ABN-493).
 */
class TwoMerchantTermsGateTest extends TestCase
{
    /**
     * Builds a Two instance with only the collaborators isAvailable() reaches,
     * injected by reflection.
     */
    private function build(array $offeredTerms): Two
    {
        $reflection = new \ReflectionClass(Two::class);
        $model = $reflection->newInstanceWithoutConstructor();

        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturn('test-api-key');

        $apiKeyStatus = $this->createMock(ApiKeyStatus::class);
        $apiKeyStatus->method('isVerified')->willReturn(true);
        $apiKeyStatus->method('getStatus')->willReturn(
            ['status' => ApiKeyStatus::OK, 'code' => 200, 'merchant' => null]
        );

        $settingsProvider = $this->createMock(SettingsProvider::class);
        $settingsProvider->method('getAvailableTerms')->willReturn($offeredTerms);

        $minimumOrderGate = $this->createMock(MinimumOrderGate::class);
        $minimumOrderGate->method('isSatisfied')->willReturn(true);

        $countriesProvider = $this->createMock(SupportedCountriesProvider::class);
        $countriesProvider->method('isAllowed')->willReturn(true);

        $properties = [
            '_scopeConfig' => $scopeConfig,
            'apiKeyStatus' => $apiKeyStatus,
            'settingsProvider' => $settingsProvider,
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

    public function testMethodIsUnavailableWhileTheMerchantRecordIsUnreachable(): void
    {
        $model = $this->build([]);

        $this->assertFalse($model->isAvailable(null));
    }

    public function testMethodIsAvailableWhenTheRecordOffersTerms(): void
    {
        $model = $this->build([14, 30]);

        $this->assertTrue($model->isAvailable(null));
    }

    public function testWithholdingIsLogged(): void
    {
        $logRepository = $this->createMock(LogRepository::class);
        $logRepository->expects($this->once())
            ->method('addDebugLog')
            ->with($this->stringContains('merchant configuration unavailable'), $this->anything());

        $model = $this->build([]);
        (new \ReflectionClass(Two::class))->getProperty('logRepository')->setValue($model, $logRepository);

        $this->assertFalse($model->isAvailable(null));
    }
}
