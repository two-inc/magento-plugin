<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Config;

use Magento\Config\Model\Config;
use Magento\Config\Model\Config\Loader;
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
 * An unusable custom term refuses the section save where the scope saved shows it but holds no row
 * of its own: the row renders inherited and disabled there, posts an inherit flag and no value, and
 * so reaches no backend model. Everywhere else the field posts its value and
 * Model\Config\Backend\PaymentTermsCustomDaysTest covers the refusal.
 *
 * A scope holding its own row is left alone deliberately. Ticking inherit there discards that row
 * for the wider scope's value, which this page never displayed — the row it lands on is the shape
 * above, refused on the next save of it.
 */
class UnusableTermRefusedWhereItShowsTest extends TestCase
{
    private const SECTION = 'two_payment';

    /** Public: the anonymous Field and Structure subclasses below read them. */
    public const STRUCTURE_PATH = 'two_payment/payment_terms/payment_terms_duration_days';

    public const CONFIG_PATH = 'payment/two_payment/payment_terms_duration_days';

    public const GROUP_PATH = 'payment/two_payment';

    private const STORE_CODE = 'de';

    private const STORE_ID = 5;

    private const WEBSITE_CODE = 'eu';

    private const WEBSITE_ID = 2;

    /** @param array<string, string>|null $posted null where the form never showed the field */
    private function refusal(
        ?array $posted,
        string $scopeParam,
        bool $ownRow,
        string $shown,
        bool $envLocked = false,
        string $section = self::SECTION
    ): ?string {
        $editedScope = $scopeParam === 'store'
            ? ['stores', self::STORE_CODE, self::STORE_ID]
            : ['websites', self::WEBSITE_CODE, self::WEBSITE_ID];

        // Any other scope answers with something unusable, so a mis-scoped read cannot pass as one.
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(
            static fn ($path, $scopeType = 'default', $scopeCode = null) =>
                [$path, $scopeType, $scopeCode] === [self::CONFIG_PATH, $editedScope[0], $editedScope[1]]
                    ? $shown
                    : 'wrong-scope'
        );

        $settingChecker = $this->createMock(SettingChecker::class);
        $settingChecker->method('isReadOnly')->willReturnCallback(
            static fn ($path, $scope, $scopeCode = null) => $envLocked
                && [$path, $scope, $scopeCode] === [self::CONFIG_PATH, $editedScope[0], $editedScope[1]]
        );

        $plugin = new RefuseUnusableCustomTerm(
            $this->structure(),
            $scopeConfig,
            $settingChecker,
            $this->storeManager(),
            $this->configLoader($ownRow, [$editedScope[0], $editedScope[2]])
        );

        try {
            $plugin->beforeSave($this->section($section, $posted, $scopeParam));
        } catch (LocalizedException $e) {
            return $e->getMessage();
        }

        return null;
    }

    /**
     * Rows at the scope queried, keyed by path as Magento\Config\Model\Config\Loader keys them.
     *
     * @param array{string, int} $editedScope
     */
    private function configLoader(bool $ownRow, array $editedScope): Loader
    {
        return new class ($ownRow, $editedScope) extends Loader {
            // phpcs:disable
            public function __construct(private bool $ownRow, private array $editedScope)
            {
            }
            public function getConfigByPath($path, $scope, $scopeId, $full = true)
            {
                $queried = [$path, $scope, $scopeId]
                    === [UnusableTermRefusedWhereItShowsTest::GROUP_PATH, $this->editedScope[0], $this->editedScope[1]];

                return $this->ownRow && $queried
                    ? [UnusableTermRefusedWhereItShowsTest::CONFIG_PATH => 'abc']
                    : [];
            }
            // phpcs:enable
        };
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
                return $path === UnusableTermRefusedWhereItShowsTest::STRUCTURE_PATH
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
                return UnusableTermRefusedWhereItShowsTest::STRUCTURE_PATH;
            }
            public function getConfigPath()
            {
                return $this->configPath;
            }
            public function getGroupPath()
            {
                return UnusableTermRefusedWhereItShowsTest::GROUP_PATH;
            }
            // phpcs:enable
        };
    }

    private function storeManager(): StoreManagerInterface
    {
        $store = $this->createMock(StoreInterface::class);
        $store->method('getCode')->willReturn(self::STORE_CODE);
        $store->method('getId')->willReturn(self::STORE_ID);
        $website = $this->createMock(WebsiteInterface::class);
        $website->method('getCode')->willReturn(self::WEBSITE_CODE);
        $website->method('getId')->willReturn(self::WEBSITE_ID);

        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);
        $storeManager->method('getWebsite')->willReturn($website);

        return $storeManager;
    }

    /**
     * @param array<string, string>|null $posted
     * @dataProvider refusalProvider
     */
    public function testTheSectionSaveIsRefusedWhereTheValueItShowsIsUnusable(
        ?array $posted,
        string $scopeParam,
        bool $ownRow,
        string $shown,
        bool $envLocked,
        string $section,
        bool $expected,
        string $case
    ): void {
        $this->assertSame(
            $expected,
            $this->refusal($posted, $scopeParam, $ownRow, $shown, $envLocked, $section) !== null,
            $case
        );
    }

    public static function refusalProvider(): array
    {
        $inheriting = ['value' => 'abc', 'inherit' => '1'];

        return [
            [$inheriting, 'store', false, 'abc', false, self::SECTION, true, 'a store view showing inherited junk cannot be saved'],
            [$inheriting, 'website', false, 'abc', false, self::SECTION, true, 'a website showing inherited junk cannot be saved'],
            [$inheriting, 'store', true, 'abc', false, self::SECTION, false, 'a row of its own is the value on the page, so the tick discarding it is not refused'],
            [$inheriting, 'store', false, '30', false, self::SECTION, false, 'a usable inherited term is not refused'],
            [$inheriting, 'store', false, '37', false, self::SECTION, false, 'a term the record does not offer is still usable'],
            [$inheriting, 'store', false, '', false, self::SECTION, false, 'nothing inherited leaves nothing to refuse'],
            [$inheriting, 'store', false, '0', false, self::SECTION, false, 'a zero reads as blank, not as junk'],
            [['value' => 'abc'], 'store', false, 'abc', false, self::SECTION, false, 'a value posted for writing is refused by its backend model instead'],
            [$inheriting, 'default', false, 'abc', false, self::SECTION, false, 'a tick at default scope removes the value rather than adopting one'],
            [$inheriting, 'store', false, 'abc', true, self::SECTION, false, 'env.php holds the value, so refusing would leave no way out'],
            [null, 'store', false, 'abc', false, self::SECTION, false, 'a field the form never posted is not this save'],
            [$inheriting, 'store', false, 'abc', false, 'other_payment', false, 'a section that does not declare the field'],
        ];
    }

    /** The value is on the page but stored wider than it, so the refusal has to say where it is. */
    public function testTheRefusalNamesTheValueAndBothWaysOutOfIt(): void
    {
        $this->assertSame(
            'Custom payment terms (days) holds "abc", which is not a usable number of days: untick the'
            . ' inherit box on that field and choose Remove to clear it here, or choose Remove at the'
            . ' scope it is set on.',
            $this->refusal(['value' => 'abc', 'inherit' => '1'], 'store', false, 'abc')
        );
    }
}
