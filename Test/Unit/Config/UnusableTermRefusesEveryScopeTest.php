<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Config;

use Magento\Config\Model\Config;
use Magento\Config\Model\Config\Reader\Source\Deployed\SettingChecker;
use Magento\Config\Model\Config\Structure;
use Magento\Config\Model\Config\Structure\Element\Field;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Api\Data\WebsiteInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Plugin\Config\RefuseUnusableCustomTerm;

/**
 * An unusable custom term refuses the section save at every scope where it is in effect, not only
 * the one that stores it — and never where the merchant has no way to remove it, which is the
 * deadlock ABN-522 exists to remove.
 *
 * Model\Config\Backend\PaymentTermsCustomDaysTest covers the value posted for writing; these are
 * the store and website scopes, where the field posts an inherit flag and never reaches a backend
 * model at all.
 */
class UnusableTermRefusesEveryScopeTest extends TestCase
{
    private const SECTION = 'two_payment';

    /** Public: the anonymous Field and Structure subclasses below read it. */
    public const STRUCTURE_PATH = 'two_payment/payment_terms/payment_terms_duration_days';

    private const CONFIG_PATH = 'payment/two_payment/payment_terms_duration_days';

    private const STORE_CODE = 'de';

    private const WEBSITE_ID = 7;

    /** @param array<string, string>|null $posted null where the form never showed the field */
    private function refusal(
        ?array $posted,
        string $scopeParam,
        string $inherited,
        bool $envLocked = false,
        string $section = self::SECTION
    ): ?string {
        $editedScope = $scopeParam === 'store' ? ['stores', self::STORE_CODE] : ['websites', 'eu'];
        $parentRead = $scopeParam === 'store'
            ? [self::CONFIG_PATH, 'website', self::WEBSITE_ID]
            : [self::CONFIG_PATH, 'default', null];

        // Any other scope answers with something unusable, so a mis-scoped read cannot pass as one.
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(
            static fn ($path, $scopeType = 'default', $scopeCode = null) =>
                [$path, $scopeType, $scopeCode] === $parentRead ? $inherited : 'wrong-scope'
        );

        $settingChecker = $this->createMock(SettingChecker::class);
        $settingChecker->method('isReadOnly')->willReturnCallback(
            static fn ($path, $scope, $scopeCode = null) => $envLocked
                && [$path, $scope, $scopeCode] === [self::STRUCTURE_PATH, $editedScope[0], $editedScope[1]]
        );

        $plugin = new RefuseUnusableCustomTerm(
            $this->structure(),
            $scopeConfig,
            $settingChecker,
            $this->storeManager()
        );

        try {
            $plugin->beforeSave($this->section($section, $posted, $scopeParam));
        } catch (LocalizedException $e) {
            return $e->getMessage();
        }

        return null;
    }

    /** @param array<string, string>|null $posted */
    private function section(string $section, ?array $posted, string $scopeParam): Config
    {
        $groups = ['payment_terms' => ['fields' => $posted === null ? [] : [
            'payment_terms_duration_days' => $posted,
        ]]];

        return new class ($section, $groups, $scopeParam) extends Config {
            // phpcs:disable
            public function __construct(private string $section, private array $groups, private string $scopeParam)
            {
            }
            public function getSection()
            {
                return $this->section;
            }
            public function getGroups()
            {
                return $this->groups;
            }
            public function getStore()
            {
                return $this->scopeParam === 'store' ? '5' : '';
            }
            public function getWebsite()
            {
                return $this->scopeParam === 'website' ? '2' : '';
            }
            // phpcs:enable
        };
    }

    private function structure(): Structure
    {
        return new class (self::field(self::CONFIG_PATH), self::field(null)) extends Structure {
            // phpcs:disable
            public function __construct(private Field $declared, private Field $undeclared)
            {
            }
            public function getElement($path)
            {
                return $path === UnusableTermRefusesEveryScopeTest::STRUCTURE_PATH
                    ? $this->declared
                    : $this->undeclared;
            }
            // phpcs:enable
        };
    }

    /** Anonymous Field subclass, as HideFieldsUnlessConfiguredTest::field(): the CI stub has no methods to mock. */
    private static function field(?string $configPath): Field
    {
        return new class ($configPath) extends Field {
            // phpcs:disable
            public function __construct(private ?string $configPath)
            {
            }
            public function getPath($fieldPrefix = '')
            {
                return UnusableTermRefusesEveryScopeTest::STRUCTURE_PATH;
            }
            public function getConfigPath()
            {
                return $this->configPath;
            }
            // phpcs:enable
        };
    }

    private function storeManager(): StoreManagerInterface
    {
        $store = $this->createMock(StoreInterface::class);
        $store->method('getCode')->willReturn(self::STORE_CODE);
        $store->method('getWebsiteId')->willReturn(self::WEBSITE_ID);
        $website = $this->createMock(WebsiteInterface::class);
        $website->method('getCode')->willReturn('eu');

        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);
        $storeManager->method('getWebsite')->willReturn($website);

        return $storeManager;
    }

    /**
     * @param array<string, string>|null $posted
     * @dataProvider refusalProvider
     */
    public function testTheSectionSaveIsRefusedWhereAnUnusableValueStaysInEffect(
        ?array $posted,
        string $scopeParam,
        string $inherited,
        bool $envLocked,
        string $section,
        bool $expected,
        string $case
    ): void {
        $this->assertSame(
            $expected,
            $this->refusal($posted, $scopeParam, $inherited, $envLocked, $section) !== null,
            $case
        );
    }

    public static function refusalProvider(): array
    {
        $inheriting = ['value' => 'abc', 'inherit' => '1'];

        return [
            [$inheriting, 'store', 'abc', false, self::SECTION, true, 'a store view inheriting junk cannot be saved'],
            [$inheriting, 'website', 'abc', false, self::SECTION, true, 'a website inheriting junk cannot be saved'],
            [$inheriting, 'store', '30', false, self::SECTION, false, 'a usable inherited term is not refused'],
            [$inheriting, 'store', '37', false, self::SECTION, false, 'a term the record does not offer is still usable'],
            [$inheriting, 'store', '', false, self::SECTION, false, 'nothing inherited leaves nothing to refuse'],
            [$inheriting, 'store', '0', false, self::SECTION, false, 'a zero reads as blank, not as junk'],
            [['value' => 'abc'], 'store', 'abc', false, self::SECTION, false, 'a value posted for writing is refused by its backend model instead'],
            [$inheriting, 'default', 'abc', false, self::SECTION, false, 'the default scope inherits from nothing wider'],
            [$inheriting, 'store', 'abc', true, self::SECTION, false, 'env.php holds the value, so refusing would leave no way out'],
            [null, 'store', 'abc', false, self::SECTION, false, 'a field the form never posted is not this save'],
            [$inheriting, 'store', 'abc', false, 'other_payment', false, 'a section that does not declare the field'],
        ];
    }

    /** At store scope the value is not on the merchant's page, so the refusal has to say where it is. */
    public function testTheRefusalNamesTheValueAndBothWaysOutOfIt(): void
    {
        $this->assertSame(
            'Custom payment terms (days) holds "abc", which is not a usable number of days: untick the'
            . ' inherit box on that field and choose Remove to clear it here, or choose Remove at the'
            . ' scope it is set on.',
            $this->refusal(['value' => 'abc', 'inherit' => '1'], 'store', 'abc')
        );
    }
}
