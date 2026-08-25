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
 * TWO-25386: config accessors for the 8 admin controls.
 */
class RepositoryAdminControlsTest extends TestCase
{
    private const VENDOR_SITE_NAME_PATH = 'payment/two_payment/vendor_site_name';
    private const SUBTITLE_PATH = 'payment/two_payment/subtitle';
    private const ABOUT_LINK_PATH = 'payment/two_payment/show_about_link';
    private const TOOLTIPS_PATH = 'payment/two_payment/display_tooltips';
    private const SKIP_NONCE_PATH = 'payment/two_payment/skip_confirm_nonce_check';
    private const CLEAR_ON_UNINSTALL_PATH = 'payment/two_payment/clear_settings_on_uninstall';
    private const DISABLE_SSL_VERIFY_PATH = 'payment/two_payment/disable_ssl_verify';

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

    public function testGetVendorSiteNameReadsItsOwnPath(): void
    {
        $this->scopeConfig->expects($this->once())
            ->method('getValue')
            ->with(self::VENDOR_SITE_NAME_PATH, ScopeInterface::SCOPE_STORE, null)
            ->willReturn('acme-eu-store');

        $this->assertSame('acme-eu-store', $this->repository->getVendorSiteName());
    }

    public function testGetVendorSiteNameDefaultsToEmptyString(): void
    {
        $this->scopeConfig->method('getValue')->willReturn(null);

        $this->assertSame('', $this->repository->getVendorSiteName());
    }

    public function testGetSubtitleReadsItsOwnPath(): void
    {
        $this->scopeConfig->expects($this->once())
            ->method('getValue')
            ->with(self::SUBTITLE_PATH, ScopeInterface::SCOPE_STORE, 3)
            ->willReturn('Buy now, pay later');

        $this->assertSame('Buy now, pay later', $this->repository->getSubtitle(3));
    }

    public function testGetSubtitleDefaultsToEmptyString(): void
    {
        $this->scopeConfig->method('getValue')->willReturn(null);

        $this->assertSame('', $this->repository->getSubtitle());
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function booleanFlagProvider(): array
    {
        return [
            'about link' => ['isAboutLinkEnabled', self::ABOUT_LINK_PATH],
            'display tooltips' => ['isDisplayTooltipsEnabled', self::TOOLTIPS_PATH],
            'skip confirm nonce check' => ['isSkipConfirmNonceCheckEnabled', self::SKIP_NONCE_PATH],
            'clear settings on uninstall' => ['isClearSettingsOnUninstallEnabled', self::CLEAR_ON_UNINSTALL_PATH],
            'disable ssl verify' => ['isSslVerificationDisabled', self::DISABLE_SSL_VERIFY_PATH],
        ];
    }

    /**
     * @dataProvider booleanFlagProvider
     */
    public function testBooleanFlagReadsItsOwnPathAndScope(string $method, string $expectedPath): void
    {
        $this->scopeConfig->expects($this->once())
            ->method('isSetFlag')
            ->with($expectedPath, ScopeInterface::SCOPE_STORE, null)
            ->willReturn(true);

        $this->assertTrue($this->repository->{$method}());
    }

    /**
     * @dataProvider booleanFlagProvider
     */
    public function testBooleanFlagDefaultsToFalse(string $method): void
    {
        $this->scopeConfig->method('isSetFlag')->willReturn(false);

        $this->assertFalse($this->repository->{$method}());
    }
}
