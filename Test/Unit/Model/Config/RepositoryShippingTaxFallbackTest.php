<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Model\Config;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ProductMetadataInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Tax\Model\Calculation as TaxCalculation;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\BrandRegistryInterface;
use Two\Gateway\Api\Log\RepositoryInterface as LogRepository;
use Two\Gateway\Model\Config\Repository;
use Two\Gateway\Model\Provenance;
use Two\Gateway\Service\Merchant\SettingsProvider;

/**
 * TWO-26082: the shipping tax fallback is off unless set by CLI for a store,
 * under the active brand's own payment/<code>/ path.
 */
class RepositoryShippingTaxFallbackTest extends TestCase
{
    /**
     * @dataProvider fallbackFlagCases
     *
     * @param array<string, bool> $storeFlags "<path>@<store id>" => stored flag, absent for unset
     */
    public function testShippingTaxFallbackFlag(
        string $brandCode,
        array $storeFlags,
        ?int $storeId,
        bool $expected,
        string $case
    ): void {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('isSetFlag')->willReturnCallback(
            static fn (string $path, string $scope, $scopeCode) => $scope === ScopeInterface::SCOPE_STORE
                && ($storeFlags[$path . '@' . $scopeCode] ?? false)
        );
        $brandRegistry = $this->createMock(BrandRegistryInterface::class);
        $brandRegistry->method('getCode')->willReturn($brandCode);

        $repository = new Repository(
            $scopeConfig,
            $this->createMock(EncryptorInterface::class),
            $this->createMock(UrlInterface::class),
            $this->createMock(ProductMetadataInterface::class),
            $this->getMockBuilder(TaxCalculation::class)->disableOriginalConstructor()->getMock(),
            $brandRegistry,
            $this->createMock(SettingsProvider::class),
            $this->createMock(Provenance::class),
            $this->createMock(LogRepository::class)
        );

        $this->assertSame($expected, $repository->isShippingTaxFallbackEnabled($storeId), $case);
    }

    public static function fallbackFlagCases(): array
    {
        $two = 'payment/two_payment/enable_shipping_tax_fallback';
        $brand = 'payment/acme_payment/enable_shipping_tax_fallback';
        return [
            ['two_payment', [], 1, false, 'off by default'],
            ['two_payment', [$two . '@1' => true], 1, true, 'on at store scope'],
            ['two_payment', [$two . '@1' => true], 2, false, 'on for one store only'],
            ['acme_payment', [$brand . '@1' => true], 1, true, 'brand overlay reads its own path'],
            ['acme_payment', [$two . '@1' => true], 1, false, 'brand overlay ignores the two_payment path'],
        ];
    }
}
