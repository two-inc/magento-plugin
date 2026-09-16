<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Model\AdminNotification;

use Magento\Backend\Model\UrlInterface;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\BrandRegistryInterface;
use Two\Gateway\Api\Config\RepositoryInterface as ConfigRepository;
use Two\Gateway\Model\AdminNotification\TrustedProxiesMessage;

class TrustedProxiesMessageTest extends TestCase
{
    private function message(
        bool $rateLimitDisabled,
        array $trustedProxies,
        ?string $productName = 'Two',
        string $sectionPrefix = 'two'
    ): TrustedProxiesMessage {
        $configRepository = $this->createMock(ConfigRepository::class);
        $configRepository->method('isRateLimitDisabled')->willReturn($rateLimitDisabled);
        $configRepository->method('getTrustedProxies')->willReturn($trustedProxies);

        $backendUrl = $this->createMock(UrlInterface::class);
        $backendUrl->method('getUrl')->willReturnCallback(
            static fn (string $path): string => 'https://shop.example/admin/' . $path
        );

        $brandRegistry = $this->createMock(BrandRegistryInterface::class);
        $brandRegistry->method('getSectionPrefix')->willReturn($sectionPrefix);
        if ($productName === null) {
            $brandRegistry->method('getProductName')->willThrowException(new \DomainException('no brands registered'));
        } else {
            $brandRegistry->method('getProductName')->willReturn($productName);
        }

        return new TrustedProxiesMessage($configRepository, $backendUrl, $brandRegistry);
    }

    /**
     * Given a store's ceiling and proxy list; When the admin loads the backend;
     * Then the notice stands only while buyers can be collapsed into one bucket.
     *
     * @dataProvider storeConfigurations
     */
    public function testTheNoticeStandsOnlyWhileTheCeilingCanCollapseBuyersIntoOneBucket(
        bool $rateLimitDisabled,
        array $trustedProxies,
        bool $displayed,
        string $description
    ): void {
        $this->assertSame(
            $displayed,
            $this->message($rateLimitDisabled, $trustedProxies)->isDisplayed(),
            $description
        );
    }

    /**
     * @return array<string, array{0: bool, 1: string[], 2: bool, 3: string}>
     */
    public static function storeConfigurations(): array
    {
        return [
            'shipped default' => [false, [], true, 'the ceiling is on with no proxy list, which is the risk'],
            'proxies set' => [false, ['10.0.0.0/8'], false, 'the ceiling can tell buyers apart'],
            'ceiling off' => [true, [], false, 'no ceiling, nothing to warn about'],
            'both addressed' => [true, ['10.0.0.0/8'], false, 'neither condition holds'],
        ];
    }

    /**
     * Given a brand's section prefix; When the notice renders;
     * Then its link targets that brand's own synthesised Version section.
     *
     * @dataProvider sectionPrefixes
     */
    public function testTheNoticeLinksTheSettingItAsksForInTheActiveBrandsSection(
        string $sectionPrefix,
        string $expectedHref,
        string $description
    ): void {
        $this->assertStringContainsString(
            'href="' . $expectedHref . '"',
            $this->message(false, [], 'Two', $sectionPrefix)->getText(),
            $description
        );
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function sectionPrefixes(): array
    {
        return [
            'vanilla' => [
                'two',
                'https://shop.example/admin/adminhtml/system_config/edit/section/two_version',
                'the unbranded install keeps its own section id',
            ],
            'overlay' => [
                'acme',
                'https://shop.example/admin/adminhtml/system_config/edit/section/acme_version',
                'a debranded install links the only section that exists there',
            ],
        ];
    }

    public function testAnUnresolvableBrandWithholdsTheNoticeRatherThanBreakingTheAdminStack(): void
    {
        $message = $this->message(false, [], null);

        $this->assertFalse($message->isDisplayed());
        $this->assertSame('', $message->getText());
    }

    /**
     * @dataProvider noticeBrands
     */
    public function testTheNoticeOpensWithTheActiveBrandsProductName(
        string $productName,
        string $description
    ): void {
        $this->assertStringStartsWith(
            $productName . ': checkout rate limiting is on',
            $this->message(false, [], $productName)->getText(),
            $description
        );
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function noticeBrands(): array
    {
        return [
            'vanilla' => ['Two', 'the unbranded install still names Two'],
            'overlay' => ['Acme Pay', 'a debranded install names its own product'],
        ];
    }
}
