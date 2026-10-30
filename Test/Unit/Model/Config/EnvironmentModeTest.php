<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Model\Config;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ProductMetadataInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\Message\ManagerInterface as MessageManager;
use Magento\Framework\Model\Context;
use Magento\Framework\Registry;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Tax\Model\Calculation as TaxCalculation;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\BrandRegistryInterface;
use Two\Gateway\Api\Config\RepositoryInterface;
use Two\Gateway\Api\Log\RepositoryInterface as LogRepository;
use Two\Gateway\Model\Config\AdminScope;
use Two\Gateway\Model\Config\Backend\Mode as ModeBackend;
use Two\Gateway\Model\Config\EnvironmentMode;
use Two\Gateway\Model\Config\Repository;
use Two\Gateway\Model\Config\Source\Mode as ModeSource;
use Two\Gateway\Model\Provenance;
use Two\Gateway\Service\Merchant\SettingsProvider;

/**
 * Environment setting (TWO-26604), driven by the shared case table
 * _files/environment-cases.json, which is byte-identical in the WooCommerce
 * and PrestaShop plugins.
 */
class EnvironmentModeTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('TWO_INTERNAL_ENVIRONMENTS');
    }

    private static function cases(string $set): array
    {
        $cases = json_decode((string)file_get_contents(__DIR__ . '/_files/environment-cases.json'), true);
        $rows = [];
        foreach ($cases[$set] as $case) {
            $rows[$case['description']] = [$case];
        }
        return $rows;
    }

    public static function resolveCases(): array
    {
        return self::cases('resolve');
    }

    public static function saveCases(): array
    {
        return self::cases('save');
    }

    private static function setFlag(?string $flag): void
    {
        putenv($flag === null ? 'TWO_INTERNAL_ENVIRONMENTS' : 'TWO_INTERNAL_ENVIRONMENTS=' . $flag);
    }

    /** The fresh-install value Magento serves: the module's config.xml default. */
    private static function installDefault(): string
    {
        $xml = simplexml_load_file(dirname(__DIR__, 4) . '/etc/config.xml');
        return (string)$xml->default->payment->two_payment->mode;
    }

    private function repository($stored): Repository
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturn($stored);
        $brand = $this->createMock(BrandRegistryInterface::class);
        $brand->method('getCheckoutUrlTemplate')->willReturn('https://%s.two.inc');
        $brand->method('getCode')->willReturn('two_payment');

        return new Repository(
            $scopeConfig,
            $this->createMock(EncryptorInterface::class),
            $this->createMock(UrlInterface::class),
            $this->createMock(ProductMetadataInterface::class),
            $this->createMock(TaxCalculation::class),
            $brand,
            $this->createMock(SettingsProvider::class),
            $this->createMock(Provenance::class),
            $this->createMock(LogRepository::class)
        );
    }

    public function testInstallDefaultIsSandbox(): void
    {
        $this->assertSame(EnvironmentMode::DEFAULT_MODE, self::installDefault());
    }

    /**
     * @dataProvider resolveCases
     */
    public function testResolveCase(array $case): void
    {
        self::setFlag($case['flag']);
        $stored = $case['stored'] ?? self::installDefault();
        $repository = $this->repository($stored);
        $description = $case['description'];

        $this->assertSame($case['options'], EnvironmentMode::options($stored), $description . ' (options)');
        $this->assertSame($case['effective'], $repository->getMode(), $description . ' (effective)');
        $this->assertSame($case['api_host'], $repository->getCheckoutApiUrl(), $description . ' (API host)');
        $this->assertSame($case['checkout_host'], $repository->getCheckoutPageUrl(), $description . ' (checkout host)');
        $this->assertSame($case['health_ok'], EnvironmentMode::isHealthy($stored), $description . ' (health)');
    }

    /**
     * @dataProvider saveCases
     */
    public function testSaveCase(array $case): void
    {
        self::setFlag($case['flag']);
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturn($case['stored']);
        $messages = $this->createMock(MessageManager::class);
        $messages->expects($case['accepted'] ? $this->never() : $this->once())
            ->method('addErrorMessage')
            ->with($this->callback(static function ($message): bool {
                return (string)$message === 'Select a valid environment.';
            }));
        $backend = new ModeBackend(
            $this->getMockBuilder(Context::class)->disableOriginalConstructor()->getMock(),
            $this->getMockBuilder(Registry::class)->disableOriginalConstructor()->getMock(),
            $scopeConfig,
            $this->createMock(TypeListInterface::class),
            $messages,
            null,
            null,
            ['value' => $case['submitted'], 'path' => 'payment/two_payment/mode']
        );

        $backend->beforeSave();

        $this->assertSame($case['accepted'], $backend->isSaveAllowed(), $case['description']);
    }

    /**
     * Magento's column of the migration table.
     *
     * @dataProvider migrationCases
     */
    public function testMigration(string $stored, string $expected, string $description): void
    {
        $this->assertSame($expected, EnvironmentMode::migrate($stored), $description);
    }

    public static function migrationCases(): array
    {
        return [
            ['production', 'production', 'production kept'],
            ['PROD', 'production', 'upper-case alias to production'],
            ['Production', 'production', 'mixed-case production lowercased'],
            ['prod', 'production', 'alias to production'],
            ['sandbox', 'sandbox', 'sandbox kept'],
            ['SANDBOX', 'sandbox', 'upper-case sandbox lowercased'],
            ['staging', 'staging', 'staging kept'],
            ['STAGING', 'staging', 'staging in any case kept'],
            ['development', 'sandbox', 'development resolved to a host that does not exist, so sandbox'],
            ['Demo', 'sandbox', 'any other value to sandbox'],
        ];
    }

    /** The dropdown reads the stored value at the scope being edited. */
    public function testSourceModelOffersTheOptionsInOrder(): void
    {
        $cases = [
            [null, 'sandbox', ['production' => 'Production', 'sandbox' => 'Sandbox'], 'outside Two infra: production then sandbox'],
            ['1', 'sandbox', ['production' => 'Production', 'sandbox' => 'Sandbox', 'staging' => 'Staging'], 'on Two infra: staging last'],
            [null, 'staging', ['production' => 'Production', 'sandbox' => 'Sandbox', 'staging' => 'Staging'], 'a stored staging stays offered'],
        ];

        foreach ($cases as [$flag, $stored, $expected, $description]) {
            self::setFlag($flag);
            $repository = $this->createMock(RepositoryInterface::class);
            $repository->method('getStoredMode')->with(null, 'default')->willReturn($stored);
            $request = $this->createMock(RequestInterface::class);
            $request->method('getParam')->willReturn(null);
            $source = new ModeSource(
                $repository,
                $request,
                new AdminScope($this->createMock(StoreManagerInterface::class))
            );

            $options = [];
            foreach ($source->toOptionArray() as $option) {
                $options[$option['value']] = (string)$option['label'];
            }
            $this->assertSame($expected, $options, $description);
        }
    }
}
