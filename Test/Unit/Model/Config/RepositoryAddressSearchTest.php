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
use Two\Gateway\Model\Config\Repository;
use Two\Gateway\Model\Provenance;
use Two\Gateway\Service\Merchant\SettingsProvider;

/**
 * TWO-25503: `isAddressSearchEnabled()` is the AND of `enable_address_search`
 * and `enable_company_search`. `enable_company_search` OFF relocates company
 * search to the payment tile rather than disabling it, but it retires the
 * convenience "Autofill company address" exists for, so autofill is forced
 * off with it.
 */
class RepositoryAddressSearchTest extends TestCase
{
    private const COMPANY_PATH = 'payment/two_payment/enable_company_search';
    private const ADDRESS_PATH = 'payment/two_payment/enable_address_search';

    /** @var ScopeConfigInterface|\PHPUnit\Framework\MockObject\MockObject */
    private $scopeConfig;

    /** @var Repository */
    private $repository;

    protected function setUp(): void
    {
        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);

        $brandRegistry = $this->createMock(BrandRegistryInterface::class);
        $brandRegistry->method('getCode')->willReturn('two_payment');

        $this->repository = new Repository(
            $this->scopeConfig,
            $this->createMock(EncryptorInterface::class),
            $this->createMock(UrlInterface::class),
            $this->createMock(ProductMetadataInterface::class),
            $this->getMockBuilder(TaxCalculation::class)->disableOriginalConstructor()->getMock(),
            $brandRegistry,
            $this->createMock(SettingsProvider::class),
            $this->createMock(Provenance::class)
        );
    }

    private function stubFlags(array $map): void
    {
        $this->scopeConfig->method('isSetFlag')->willReturnCallback(
            function ($path) use ($map) {
                return $map[$path] ?? false;
            }
        );
    }

    /**
     * @return array<string, array{0: bool, 1: bool, 2: bool}>
     */
    public static function toggleCombinationsProvider(): array
    {
        // company, address, expected — expected is the AND of the two.
        return [
            'both on' => [true, true, true],
            'company off, address on' => [false, true, false],
            'company on, address off' => [true, false, false],
            'both off' => [false, false, false],
        ];
    }

    /**
     * @dataProvider toggleCombinationsProvider
     */
    public function testIsAddressSearchEnabledIsTheAndOfBothFlags(
        bool $company,
        bool $address,
        bool $expected
    ): void {
        $this->stubFlags([
            self::COMPANY_PATH => $company,
            self::ADDRESS_PATH => $address,
        ]);

        $this->assertSame($expected, $this->repository->isAddressSearchEnabled());
    }

    /**
     * Short-circuits on the company flag: a stale/junk `enable_address_search`
     * row never gets read once company search is not in the address area,
     * matching the PrestaShop resolver's "gate the read" approach.
     */
    public function testIsAddressSearchEnabledNeverReadsTheAddressFlagWhenCompanySearchIsOff(): void
    {
        $this->scopeConfig->expects($this->once())
            ->method('isSetFlag')
            ->with(self::COMPANY_PATH, ScopeInterface::SCOPE_STORE, null)
            ->willReturn(false);

        $this->assertFalse($this->repository->isAddressSearchEnabled());
    }

    public function testIsCompanySearchEnabledStillReadsItsOwnFlag(): void
    {
        $this->stubFlags([self::COMPANY_PATH => true, self::ADDRESS_PATH => false]);

        $this->assertTrue($this->repository->isCompanySearchEnabled());
    }
}
