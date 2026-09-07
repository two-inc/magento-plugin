<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Plugin\Config\Structure;

use Magento\Config\Model\Config\Structure\Element\Field;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Api\Data\WebsiteInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Model\Brand\Descriptor;
use Two\Gateway\Model\Brand\Loader;
use Two\Gateway\Plugin\Config\Structure\HideFieldsUnlessConfigured;

class HideFieldsUnlessConfiguredTest extends TestCase
{
    /** Wired from the shipped di.xml; rows keyed `<config path>@<scope>:<id>`, reads inherit store 2 → website 3 → default. */
    private function plugin(array $storedRows, array $params = []): HideFieldsUnlessConfigured
    {
        $inherits = ['store:2' => 'website:3', 'website:3' => 'default:'];
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(
            static function ($path, $scopeType = 'default', $scopeCode = null) use ($storedRows, $inherits) {
                for ($scope = "$scopeType:$scopeCode"; $scope !== null; $scope = $inherits[$scope] ?? null) {
                    if (array_key_exists("$path@$scope", $storedRows)) {
                        return $storedRows["$path@$scope"];
                    }
                }
                return null;
            }
        );

        $request = $this->createMock(RequestInterface::class);
        $request->method('getParam')->willReturnCallback(static fn ($key) => $params[$key] ?? null);

        $store = $this->createMock(StoreInterface::class);
        $store->method('getId')->willReturn(2);
        $website = $this->createMock(WebsiteInterface::class);
        $website->method('getId')->willReturn(3);
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturnCallback(
            static fn ($code) => $code === 'broken' ? throw new \RuntimeException('no such store') : $store
        );
        $storeManager->method('getWebsite')->willReturn($website);

        $brands = $this->createMock(Loader::class);
        $brands->method('load')->willReturn(['acme_payment' => self::brand('acme_payment', 'acme')]);

        $predicates = [];
        foreach (self::shippedRegistry() as [$key, $class]) {
            $predicates[$key] = new $class();
        }

        return new HideFieldsUnlessConfigured($scopeConfig, $request, $storeManager, $brands, $predicates);
    }

    private static function brand(string $code, string $sectionPrefix): Descriptor
    {
        return new Descriptor(
            code: $code,
            sectionPrefix: $sectionPrefix,
            tabSortOrder: 500,
            provider: 'Acme',
            providerFullName: 'Acme',
            productName: 'Acme',
            tabLabel: 'Acme',
            tabCssClass: 'acme-extension',
            checkoutUrlTemplate: 'https://%s.example.test',
            brandTag: '',
            signUpUrl: 'https://example.test/signup',
            documentationUrl: 'https://example.test/docs',
            apiBaseUrl: 'https://api.example.test',
            cspOrigins: [],
            adminResource: 'Magento_Sales::config_sales',
            moduleLabelChain: [],
            extraHttpHeaders: []
        );
    }

    /** Anonymous Field subclass, as HidePaymentSectionTest::section(): the CI stub Field has no methods to mock. */
    private static function field(string $path, ?string $configPath): Field
    {
        return new class ($path, $configPath) extends Field {
            // phpcs:disable
            public function __construct(private string $structurePath, private ?string $configPath)
            {
            }
            public function getId()
            {
                $parts = explode('/', $this->structurePath);
                return end($parts);
            }
            public function getPath($fieldPrefix = '')
            {
                return $this->structurePath;
            }
            public function getConfigPath()
            {
                return $this->configPath;
            }
            // phpcs:enable
        };
    }

    /**
     * @param array<string, mixed> $storedRows
     * @dataProvider visibilityProvider
     */
    public function testAfterIsVisible(Field $field, bool $nativeResult, array $storedRows, bool $expected, string $case): void
    {
        $this->assertSame($expected, $this->plugin($storedRows)->afterIsVisible($field, $nativeResult), $case);
    }

    public static function visibilityProvider(): array
    {
        $rate = self::field('two_order_management/order_management/default_shipping_tax_rate', 'payment/two_payment/default_shipping_tax_rate');
        $rateRow = 'payment/two_payment/default_shipping_tax_rate@default:';
        $type = self::field('two_payment/payment_terms/payment_terms_type', 'payment/two_payment/payment_terms_type');
        $typeRow = 'payment/two_payment/payment_terms_type@default:';
        $brandType = self::field('acme_payment/payment_terms/payment_terms_type', 'payment/acme_payment/payment_terms_type');
        $brandTypeRow = 'payment/acme_payment/payment_terms_type@default:';

        return [
            [$rate, false, [$rateRow => '21.5'], false, 'already hidden natively — passed through'],
            [self::field('two_version/logging/debug', 'payment/two_payment/debug'), true, [], true, 'unregistered field — passed through'],
            [$rate, true, [], false, 'shipping rate unset — hidden'],
            [$rate, true, [$rateRow => '21.5'], true, 'shipping rate stored — shown'],
            [$rate, true, [$rateRow => '0.00'], true, 'stored 0% is a declaration — shown'],
            [$rate, true, [$rateRow => 'abc'], false, 'junk is not a declaration — hidden'],
            [$type, true, [], false, 'terms type unset — hidden'],
            [$type, true, [$typeRow => 'standard'], false, 'terms type standard — hidden'],
            [$type, true, [$typeRow => 'end_of_month'], true, 'terms type end of month — shown'],
            [$type, true, [$typeRow => ' end_of_month '], true, 'hand-edited whitespace around end of month — shown'],
            [$brandType, true, [$typeRow => 'end_of_month'], false, 'brand field reads its own row, not the base one — hidden'],
            [$brandType, true, [$brandTypeRow => 'end_of_month'], true, 'brand field with its own end of month row — shown'],
            [
                self::field('acme_order_management/order_management/default_shipping_tax_rate', 'payment/acme_payment/default_shipping_tax_rate'),
                true,
                ['payment/acme_payment/default_shipping_tax_rate@default:' => '0'],
                true,
                'brand shipping rate stored — shown',
            ],
            [
                self::field('foo_payment/payment_terms/payment_terms_type', 'payment/foo_payment/payment_terms_type'),
                true,
                [],
                true,
                'foreign section with an installed-brand-shaped id but no registered brand — passed through',
            ],
            [
                self::field('acme_checkout_fields/payment_terms/payment_terms_type', 'payment/acme_payment/payment_terms_type'),
                true,
                [],
                true,
                'brand field under a section the key does not name — passed through',
            ],
            [
                self::field('two_payment/payment_terms/nested/payment_terms_type', 'payment/two_payment/payment_terms_type'),
                true,
                [],
                true,
                'nested group — group segment is the one before the field, so unmatched — passed through',
            ],
            [
                self::field('payment_terms_type', 'payment/two_payment/payment_terms_type'),
                true,
                [],
                true,
                'bare id with no structure path — passed through',
            ],
            [
                self::field('two_payment/payment_terms/payment_terms_type', null),
                true,
                ['two_payment/payment_terms/payment_terms_type@default:' => 'end_of_month'],
                true,
                'no config_path — read at the structure path, as Magento stores it',
            ],
        ];
    }

    /**
     * @dataProvider scopeProvider
     */
    public function testReadsTheEffectiveValueAtTheScopeBeingEdited(array $params, string $scopeType, ?int $scopeId, string $case): void
    {
        $field = self::field('two_payment/payment_terms/payment_terms_type', 'payment/two_payment/payment_terms_type');
        $row = 'payment/two_payment/payment_terms_type';

        $this->assertTrue($this->plugin(["$row@$scopeType:$scopeId" => 'end_of_month'], $params)->afterIsVisible($field, true), $case);
        $this->assertFalse($this->plugin([], $params)->afterIsVisible($field, true), "$case — nothing stored anywhere");
        $this->assertFalse(
            $this->plugin(["$row@default:" => 'end_of_month', "$row@$scopeType:$scopeId" => 'standard'], $params)->afterIsVisible($field, true),
            "$case — own row overrides an inherited end of month"
        );
    }

    public static function scopeProvider(): array
    {
        return [
            [[], 'default', null, 'no scope param — default scope'],
            [['store' => 'de'], 'store', 2, 'store param — that store'],
            [['website' => 'eu'], 'website', 3, 'website param — that website, not its default store'],
            [['store' => 'de', 'website' => 'eu'], 'store', 2, 'both params — store wins'],
        ];
    }

    public function testAStoreWithNoOwnRowInheritsTheDefaultEndOfMonth(): void
    {
        $field = self::field('two_payment/payment_terms/payment_terms_type', 'payment/two_payment/payment_terms_type');
        $plugin = $this->plugin(['payment/two_payment/payment_terms_type@default:' => 'end_of_month'], ['store' => 'de']);

        $this->assertTrue($plugin->afterIsVisible($field, true));
    }

    public function testAnUnresolvableStoreParamHidesTheFieldRatherThanReadingAWiderScope(): void
    {
        $field = self::field('two_payment/payment_terms/payment_terms_type', 'payment/two_payment/payment_terms_type');
        $plugin = $this->plugin(['payment/two_payment/payment_terms_type@default:' => 'end_of_month'], ['store' => 'broken']);

        $this->assertFalse($plugin->afterIsVisible($field, true));
    }

    /**
     * A key that matches no field in both admin forms is a silent regression: the field renders unconditionally.
     *
     * @dataProvider shippedRegistry
     */
    public function testEveryShippedRegistryEntryGatesAFieldInBothAdminForms(string $key, string $class, string $case): void
    {
        [$suffix, $group, $field] = explode('/', $key);
        $root = dirname(__DIR__, 5);
        foreach (['etc/adminhtml/system.xml' => 'two', 'etc/adminhtml/brand_form_template.xml' => '{{section_prefix}}'] as $form => $prefix) {
            $xpath = sprintf('//section[@id="%s_%s"]/group[@id="%s"]/field[@id="%s"]', $prefix, $suffix, $group, $field);
            $this->assertCount(1, simplexml_load_file("$root/$form")->xpath($xpath), "$case in $form");
        }
    }

    public static function shippedRegistry(): array
    {
        $items = simplexml_load_file(dirname(__DIR__, 5) . '/etc/adminhtml/di.xml')->xpath(
            '//type[@name="Two\Gateway\Plugin\Config\Structure\HideFieldsUnlessConfigured"]'
            . '/arguments/argument[@name="predicates"]/item'
        );
        $cases = [];
        foreach ($items as $item) {
            $cases[] = [(string)$item['name'], (string)$item, sprintf('di.xml gates "%s"', (string)$item['name'])];
        }

        return $cases;
    }

    public function testTheShippedRegistryIsNotEmpty(): void
    {
        $this->assertNotEmpty(self::shippedRegistry());
    }
}
