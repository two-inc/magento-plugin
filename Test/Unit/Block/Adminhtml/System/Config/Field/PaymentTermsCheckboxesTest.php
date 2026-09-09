<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Block\Adminhtml\System\Config\Field;

use Magento\Backend\Block\Template\Context;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\BrandRegistryInterface;
use Two\Gateway\Block\Adminhtml\System\Config\Field\PaymentTermsCheckboxes;
use Two\Gateway\Service\Locale\AdminDecimalFormatter;
use Two\Gateway\Service\Merchant\SettingsProvider;

/**
 * Every scope-dependent thing the checkboxes render — which terms exist, the currency their fees
 * are labelled with, and the scope posted to the fees proxy — comes from the admin page's own
 * request params, so a store whose record offers a narrower set does not render the default set.
 */
class PaymentTermsCheckboxesTest extends TestCase
{
    public const STORE_ID = 5;
    public const WEBSITE_ID = 2;

    /** @var array<int, mixed> what storeManager::getStore() was asked for, in order */
    private $storeLookups = [];

    /** @param array<string, string> $params the admin page's own request params */
    private function block(array $params, ?SettingsProvider $settingsProvider = null): PaymentTermsCheckboxes
    {
        $request = $this->createMock(RequestInterface::class);
        $request->method('getParam')->willReturnCallback(static fn ($key) => $params[$key] ?? null);
        $context = $this->createMock(Context::class);
        $context->method('getRequest')->willReturn($request);

        $store = new class {
            public function getId()
            {
                return PaymentTermsCheckboxesTest::STORE_ID;
            }

            public function getBaseCurrencyCode()
            {
                return 'NOK';
            }
        };
        $website = new class {
            public function getId()
            {
                return PaymentTermsCheckboxesTest::WEBSITE_ID;
            }

            public function getBaseCurrencyCode()
            {
                return 'SEK';
            }
        };

        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturnCallback(function ($id) use ($store) {
            $this->storeLookups[] = $id;
            if ($id === 'broken') {
                throw new \RuntimeException('no such store');
            }

            return $store;
        });
        $storeManager->method('getWebsite')->willReturn($website);

        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturn('EUR');

        return new PaymentTermsCheckboxes(
            $context,
            $this->createMock(BrandRegistryInterface::class),
            $settingsProvider ?? $this->createMock(SettingsProvider::class),
            $storeManager,
            $scopeConfig,
            $this->createMock(AdminDecimalFormatter::class)
        );
    }

    /**
     * @param array<string, string> $params
     * @dataProvider scopeProvider
     */
    public function testTheOfferedSetIsResolvedForTheScopeBeingEdited(
        array $params,
        string $scope,
        int $scopeId,
        ?int $expectedStoreId,
        string $case
    ): void {
        $settingsProvider = $this->createMock(SettingsProvider::class);
        $settingsProvider->expects($this->once())
            ->method('getAvailableTerms')
            ->with($expectedStoreId)
            ->willReturn([30]);

        $this->assertSame([30], $this->block($params, $settingsProvider)->getAvailableTerms(), $case);
    }

    /**
     * The container's data-scope / data-scope-id are posted to the fees proxy, which prices the
     * figures beside each term.
     *
     * @param array<string, string> $params
     * @dataProvider scopeProvider
     */
    public function testTheScopeThePhtmlPostsToTheFeesProxy(
        array $params,
        string $scope,
        int $scopeId,
        ?int $expectedStoreId,
        string $case
    ): void {
        $block = $this->block($params);

        $this->assertSame([$scope, $scopeId], [$block->getScope(), $block->getScopeId()], $case);
    }

    public static function scopeProvider(): array
    {
        return [
            [['store' => 'de'], 'stores', self::STORE_ID, self::STORE_ID, 'the store param names the scope being edited'],
            [[], 'default', 0, null, 'no param is the default scope'],
            [['website' => 'eu'], 'websites', self::WEBSITE_ID, null, 'a website scope has no single store to ask for'],
            [['store' => ''], 'default', 0, null, 'an empty param is not a scope'],
            [['store' => 'broken'], 'default', 0, null, 'an unresolvable store falls back rather than throwing'],
            [['store' => 'broken', 'website' => 'eu'], 'websites', self::WEBSITE_ID, null, 'an unresolvable store falls through to the website param'],
        ];
    }

    /**
     * @param array<string, string> $params
     * @param array<int, mixed> $expectedLookups
     * @dataProvider currencyProvider
     */
    public function testTheFeeFiguresAreLabelledWithTheScopesOwnCurrency(
        array $params,
        string $expected,
        array $expectedLookups,
        string $case
    ): void {
        $this->assertSame($expected, $this->block($params)->getBaseCurrency(), $case);
        $this->assertSame($expectedLookups, $this->storeLookups, $case);
    }

    public static function currencyProvider(): array
    {
        return [
            [['store' => 'de'], 'NOK', ['de', self::STORE_ID], 'the store record answers, looked up by the id the param resolved to'],
            [[], 'EUR', [], 'the default scope answers from config'],
            [['website' => 'eu'], 'SEK', [], 'the website record answers at website scope'],
            [['store' => ''], 'EUR', [], 'an empty param leaves the default scope'],
            [['store' => 'broken'], 'EUR', ['broken'], 'an unresolvable store falls back to config'],
            [['store' => 'broken', 'website' => 'eu'], 'SEK', ['broken'], 'an unresolvable store falls through to the website param'],
        ];
    }

    /**
     * The form object never carries scope, so reading it resolved every scope to default: a store
     * rendered the default record's offered set, and the save then refused the terms it showed.
     */
    public function testTheFormObjectIsNotTheScopeSource(): void
    {
        $settingsProvider = $this->createMock(SettingsProvider::class);
        $settingsProvider->expects($this->once())->method('getAvailableTerms')->with(self::STORE_ID)->willReturn([30]);

        $form = new class {
            public function getScope(): string
            {
                return 'default';
            }

            public function getScopeId(): int
            {
                return 0;
            }
        };
        $block = $this->block(['store' => 'de'], $settingsProvider);
        $block->setData('element', new AbstractElement(['value' => '30', 'form' => $form]));

        $this->assertSame([30], $block->getAvailableTerms());
        $this->assertSame(['stores', self::STORE_ID], [$block->getScope(), $block->getScopeId()]);
    }
}
