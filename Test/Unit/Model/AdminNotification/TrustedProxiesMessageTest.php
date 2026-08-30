<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Model\AdminNotification;

use Magento\Backend\Model\UrlInterface;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\Config\RepositoryInterface as ConfigRepository;
use Two\Gateway\Model\AdminNotification\TrustedProxiesMessage;

class TrustedProxiesMessageTest extends TestCase
{
    private function message(bool $rateLimitDisabled, array $trustedProxies): TrustedProxiesMessage
    {
        $configRepository = $this->createMock(ConfigRepository::class);
        $configRepository->method('isRateLimitDisabled')->willReturn($rateLimitDisabled);
        $configRepository->method('getTrustedProxies')->willReturn($trustedProxies);

        $backendUrl = $this->createMock(UrlInterface::class);
        $backendUrl->method('getUrl')->willReturn('https://shop.example/admin/two');

        return new TrustedProxiesMessage($configRepository, $backendUrl);
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

    public function testTheNoticeLinksTheSettingItAsksFor(): void
    {
        $this->assertStringContainsString(
            'https://shop.example/admin/two',
            $this->message(false, [])->getText()
        );
    }
}
