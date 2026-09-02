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
 * TWO-25386: config accessors for the 7 admin controls.
 */
class RepositoryAdminControlsTest extends TestCase
{
    private const VENDOR_SITE_NAME_PATH = 'payment/two_payment/vendor_site_name';
    private const SUBTITLE_PATH = 'payment/two_payment/subtitle';
    private const ABOUT_LINK_PATH = 'payment/two_payment/show_about_link';
    private const TOOLTIPS_PATH = 'payment/two_payment/display_tooltips';
    private const CLEAR_ON_UNINSTALL_PATH = 'payment/two_payment/clear_settings_on_uninstall';
    private const DISABLE_SSL_VERIFY_PATH = 'payment/two_payment/disable_ssl_verify';
    private const TRUSTED_PROXIES_PATH = 'payment/two_payment/trusted_proxies';
    private const CUSTOM_HEADERS_PATH = 'payment/two_payment/custom_headers';

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

    /**
     * Given a merchant's proxy list as typed into the textarea; When it is
     * read; Then it is the set of entries, however the merchant separated them.
     *
     * @dataProvider trustedProxyInput
     */
    public function testTrustedProxiesAreReadAsASetOfEntries(
        $stored,
        array $expected,
        string $description
    ): void {
        $this->scopeConfig->expects($this->once())
            ->method('getValue')
            ->with(self::TRUSTED_PROXIES_PATH, ScopeInterface::SCOPE_STORE, null)
            ->willReturn($stored);

        $this->assertSame($expected, $this->repository->getTrustedProxies(), $description);
    }

    /**
     * @return array<string, array{0: mixed, 1: string[], 2: string}>
     */
    public static function trustedProxyInput(): array
    {
        return [
            'unset' => [null, [], 'no list configured is no trusted proxy'],
            'blank' => ['   ', [], 'whitespace alone names nothing'],
            'one address' => ['10.0.0.1', ['10.0.0.1'], 'a single entry needs no separator'],
            'commas' => ['10.0.0.1, 10.0.0.2', ['10.0.0.1', '10.0.0.2'], 'comma-separated'],
            'new lines' => ["10.0.0.1\n10.0.0.2", ['10.0.0.1', '10.0.0.2'], 'one per line'],
            'cidr kept whole' => ['10.0.0.0/8', ['10.0.0.0/8'], 'the mask is part of the entry'],
            'repeats' => ['10.0.0.1, 10.0.0.1', ['10.0.0.1'], 'a repeat is one proxy'],
        ];
    }

    /**
     * Given whatever the custom-header table holds; When the two accessors
     * read it; Then the server sees every sendable row and the browser only
     * the ticked ones.
     *
     * @dataProvider customHeaderStorage
     *
     * @param array<string, string> $expectedAll
     * @param array<string, string> $expectedBrowser
     */
    public function testCustomHeadersSplitByTheBrowserTick(
        $stored,
        array $expectedAll,
        array $expectedBrowser,
        string $description
    ): void {
        $this->scopeConfig->method('getValue')
            ->with(self::CUSTOM_HEADERS_PATH, ScopeInterface::SCOPE_STORE, null)
            ->willReturn($stored);

        $this->assertSame($expectedAll, $this->repository->getCustomHeaders(), $description);
        $this->assertSame($expectedBrowser, $this->repository->getBrowserCustomHeaders(), $description);
    }

    /**
     * @return array<string, array{0: mixed, 1: array<string, string>, 2: array<string, string>, 3: string}>
     */
    public static function customHeaderStorage(): array
    {
        $row = static fn(string $name, string $value, string $flag): array => [
            'name' => $name,
            'value' => $value,
            'send_from_browser' => $flag,
        ];

        return [
            'unset' => [null, [], [], 'no table configured is no header'],
            'blank' => ['', [], [], 'an empty table is no header'],
            'server only' => [
                (string)json_encode(['_1' => $row('X-WAF-TOKEN', 'abc', '')]),
                ['X-WAF-TOKEN' => 'abc'],
                [],
                'an unticked header never reaches the browser',
            ],
            'ticked' => [
                (string)json_encode(['_1' => $row('X-WAF-TOKEN', 'abc', '1')]),
                ['X-WAF-TOKEN' => 'abc'],
                ['X-WAF-TOKEN' => 'abc'],
                'a ticked header goes to both',
            ],
            'mixed' => [
                (string)json_encode([
                    '_1' => $row('X-WAF-TOKEN', 'abc', '1'),
                    '_2' => $row('X-Gateway', 'edge-1', ''),
                ]),
                ['X-WAF-TOKEN' => 'abc', 'X-Gateway' => 'edge-1'],
                ['X-WAF-TOKEN' => 'abc'],
                'the tick is per row',
            ],
            'zero flag' => [
                (string)json_encode(['_1' => $row('X-WAF-TOKEN', 'abc', '0')]),
                ['X-WAF-TOKEN' => 'abc'],
                [],
                "a stored '0' is off, not a truthy string",
            ],
            'junk' => ['not json', [], [], 'an unreadable value sends nothing'],
            'unusable name' => [
                (string)json_encode(['_1' => $row('X Waf', 'abc', '1')]),
                [],
                [],
                'a row that cannot be a header is dropped, however it got stored',
            ],
            'reserved name' => [
                (string)json_encode(['_1' => $row('x-api-key', 'hijacked', '1')]),
                [],
                [],
                'a stored row can never displace a header the extension sets',
            ],
            'no value' => [
                (string)json_encode(['_1' => $row('X-WAF-TOKEN', '', '1')]),
                [],
                [],
                'a valueless header is nothing to send',
            ],
            'line break in the value' => [
                (string)json_encode(['_1' => $row('X-WAF-TOKEN', "abc\r\nX-API-Key: forged", '1')]),
                [],
                [],
                'a stored value that would forge a second header is dropped',
            ],
            'the same header in two casings' => [
                (string)json_encode([
                    '_1' => $row('X-WAF-TOKEN', 'first', '1'),
                    '_2' => $row('x-waf-token', 'second', '1'),
                ]),
                ['X-WAF-TOKEN' => 'first'],
                ['X-WAF-TOKEN' => 'first'],
                'field names are case-insensitive, so the first is the one that wins',
            ],
        ];
    }

    public function testRateLimitingIsOnUnlessTheDiagnosticsToggleSaysOtherwise(): void
    {
        $this->scopeConfig->method('isSetFlag')->willReturn(false);

        $this->assertFalse($this->repository->isRateLimitDisabled());
    }

    public function testTheDiagnosticsToggleReadsItsOwnPath(): void
    {
        $this->scopeConfig->expects($this->once())
            ->method('isSetFlag')
            ->with('payment/two_payment/disable_rate_limit', ScopeInterface::SCOPE_STORE, null)
            ->willReturn(true);

        $this->assertTrue($this->repository->isRateLimitDisabled());
    }
}
