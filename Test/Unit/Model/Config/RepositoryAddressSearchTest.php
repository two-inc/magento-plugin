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
 * TWO-25202: this method reads `enable_address_search` alone. That is the
 * shared gate, not the whole feature rule — TWO-25326 added a positional
 * condition for the payment-tile picker in gateway_method.js::addressLookup(),
 * client-side and deliberately not here.
 * `enable_company_search` keeps its own separate job (the shipping-step
 * company-search widget) and must not influence address lookup.
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
        // company, address, expected — expected always tracks address.
        return [
            'both on' => [true, true, true],
            'company off, address on' => [false, true, true],
            'company on, address off' => [true, false, false],
            'both off' => [false, false, false],
        ];
    }

    /**
     * @dataProvider toggleCombinationsProvider
     */
    public function testIsAddressSearchEnabledFollowsAddressFlagAlone(
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

    public function testIsAddressSearchEnabledNeverReadsTheCompanySearchFlag(): void
    {
        $this->scopeConfig->expects($this->once())
            ->method('isSetFlag')
            ->with(self::ADDRESS_PATH, ScopeInterface::SCOPE_STORE, null)
            ->willReturn(true);

        $this->assertTrue($this->repository->isAddressSearchEnabled());
    }

    public function testIsCompanySearchEnabledStillReadsItsOwnFlag(): void
    {
        $this->stubFlags([self::COMPANY_PATH => true, self::ADDRESS_PATH => false]);

        $this->assertTrue($this->repository->isCompanySearchEnabled());
    }
}
