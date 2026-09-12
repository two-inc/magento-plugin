<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Service\Merchant;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\Config\RepositoryInterface as ConfigRepository;
use Two\Gateway\Api\Log\RepositoryInterface as LogRepository;
use Two\Gateway\Model\Config\Backend\PaymentTerms\OfferedTermsGuard;
use Two\Gateway\Service\Api\Adapter;
use Two\Gateway\Service\Merchant\RecordProvider;
use Two\Gateway\Service\Merchant\SettingsProvider;

/**
 * A website-scoped read resolves the website's own API key, so the record it gets is the
 * merchant that key names — not the default scope's merchant, and not the merchant of a
 * child store that overrides the key (ABN-530).
 */
class WebsiteScopeRecordReadTest extends TestCase
{
    use ConfiguresScopes;

    /** Store view 9 lives in website 4, and each tier holds a different key. */
    private const STORES = [9 => 4];

    private const CONFIG = [
        '9' => ['store-key', 'sandbox'],
        'websites:4' => ['website-key', 'sandbox'],
        'default:' => ['default-key', 'sandbox'],
    ];

    /** Terms the merchant behind each key offers. */
    private const TERMS = [
        'store-key' => [7],
        'website-key' => [14, 60],
        'default-key' => [14, 30],
    ];

    /** @var StoreManagerInterface|\PHPUnit\Framework\MockObject\MockObject */
    private $storeManager;

    /** @var ConfigRepository|\PHPUnit\Framework\MockObject\MockObject */
    private $configRepository;

    private function guard(): OfferedTermsGuard
    {
        $this->storeManager = $this->createMock(StoreManagerInterface::class);
        $this->configRepository = $this->createMock(ConfigRepository::class);
        $this->configure(self::STORES, self::CONFIG);

        $adapter = $this->createMock(Adapter::class);
        $adapter->method('execute')->willReturnCallback(
            static function (
                string $endpoint,
                array $payload = [],
                string $method = 'GET',
                ?int $storeId = null,
                ?string $apiKey = null
            ): array {
                if ($endpoint === '/v1/merchant/verify_api_key') {
                    return ['id' => 'merchant-of-' . $apiKey];
                }

                return ['id' => 'merchant-of-' . $apiKey, 'available_terms' => self::TERMS[$apiKey] ?? []];
            }
        );
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('load')->willReturn(false);

        return new OfferedTermsGuard(new SettingsProvider(new RecordProvider(
            $adapter,
            $this->configRepository,
            $cache,
            new Json(),
            $this->createMock(LogRepository::class)
        )));
    }

    /**
     * @param int[] $expected
     * @dataProvider scopeProvider
     */
    public function testTheOfferedSetComesFromTheScopesOwnKey(
        ?int $scopeId,
        string $scope,
        array $expected,
        string $case
    ): void {
        $this->assertSame($expected, $this->guard()->offered($scopeId, $scope), $case);
    }

    public static function scopeProvider(): array
    {
        return [
            [4, 'website', [14, 60], "a website's own key, not the default scope's and not its child store's"],
            [9, 'store', [7], "a store view's own override"],
            [null, 'default', [14, 30], 'the default scope'],
        ];
    }

    /**
     * 30 is offered by the default scope's merchant and not by the website's, so a
     * website-scoped save of it is refused rather than accepted (ABN-530).
     *
     * @dataProvider savedTermProvider
     */
    public function testAWebsiteScopedSaveIsJudgedByTheWebsitesTermSet(
        int $days,
        bool $refused,
        string $case
    ): void {
        $guard = $this->guard();
        if ($refused) {
            $this->expectException(LocalizedException::class);
        }
        $guard->assertOffered([$days], 4, 'website');

        $this->assertTrue(true, $case);
    }

    public static function savedTermProvider(): array
    {
        return [
            [60, false, "offered by the website's merchant"],
            [30, true, "offered by the default scope's merchant only"],
            [14, false, 'offered by both'],
        ];
    }
}
