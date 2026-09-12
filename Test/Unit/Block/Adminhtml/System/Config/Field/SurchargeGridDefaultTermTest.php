<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Block\Adminhtml\System\Config\Field;

use Magento\Backend\Block\Template\Context;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\BrandRegistryInterface;
use Two\Gateway\Api\CurrencyRatesProviderInterface;
use Two\Gateway\Block\Adminhtml\System\Config\Field\SurchargeGrid;
use Two\Gateway\Service\Locale\AdminDecimalFormatter;
use Two\Gateway\Service\Merchant\SettingsProvider;

/**
 * Differential mode disables and zeroes the row it prices against, so the grid
 * has to name the term the checkout resolves — not the stored one, which is
 * empty wherever the admin left the choice to the resolver (ABN-548). The
 * record is read at the scope the form is editing (ABN-530).
 */
class SurchargeGridDefaultTermTest extends TestCase
{
    public const STORE_ID = 5;
    public const WEBSITE_ID = 2;

    /** @var array<string, mixed> */
    private $config = [];

    /** @var array{0: int|null, 1: string}|null what the record was asked for */
    private $recordScope = null;

    /**
     * @param array<string, string> $params the admin page's own request params
     * @param int[] $offered
     */
    private function block(array $params, array $offered, ?int $apiDefault): SurchargeGrid
    {
        $request = $this->createMock(RequestInterface::class);
        $request->method('getParam')->willReturnCallback(static fn ($key) => $params[$key] ?? null);
        $context = $this->createMock(Context::class);
        $context->method('getRequest')->willReturn($request);

        $store = $this->createConfiguredMock(\Magento\Store\Api\Data\StoreInterface::class, ['getId' => self::STORE_ID]);
        $website = $this->createConfiguredMock(\Magento\Store\Api\Data\WebsiteInterface::class, ['getId' => self::WEBSITE_ID]);
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturnCallback(
            static fn ($code) => $code === 'broken' ? throw new \RuntimeException('no such store') : $store
        );
        $storeManager->method('getWebsite')->willReturn($website);

        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(
            fn (string $path) => $this->config[$path] ?? null
        );

        $brandRegistry = $this->createMock(BrandRegistryInterface::class);
        $brandRegistry->method('getCode')->willReturn('two_payment');

        $settingsProvider = $this->createMock(SettingsProvider::class);
        $settingsProvider->method('getAvailableTerms')->willReturn($offered);
        $settingsProvider->method('getDefaultTerm')->willReturnCallback(
            function ($scopeId, $scope) use ($apiDefault) {
                $this->recordScope = [$scopeId, $scope];

                return $apiDefault;
            }
        );

        $block = new SurchargeGrid(
            $context,
            $scopeConfig,
            $storeManager,
            $this->createMock(CurrencyRatesProviderInterface::class),
            $brandRegistry,
            $settingsProvider,
            $this->createMock(AdminDecimalFormatter::class),
            $this->createMock(ResourceConnection::class)
        );
        // render() is what resolves the scope every read then runs at. It
        // strips the element's inherit affordances first, and those are
        // chainable on the real element.
        $block->render(new class ([]) extends AbstractElement {
            public function unsScope(): self
            {
                return $this;
            }

            public function unsCanUseWebsiteValue(): self
            {
                return $this;
            }

            public function unsCanUseDefaultValue(): self
            {
                return $this;
            }
        });

        return $block;
    }

    /**
     * @param int[] $offered
     * @dataProvider defaultTermProvider
     */
    public function testTheTermDifferentialModePricesAgainst(
        string $configured,
        string $custom,
        array $offered,
        string $storedDefault,
        ?int $apiDefault,
        int $expected,
        string $case
    ): void {
        $this->config = [
            'payment/two_payment/payment_terms' => $configured,
            'payment/two_payment/payment_terms_duration_days' => $custom,
            'payment/two_payment/default_payment_term' => $storedDefault,
        ];

        $this->assertSame($expected, $this->block([], $offered, $apiDefault)->getDefaultTerm(), $case);
    }

    public static function defaultTermProvider(): array
    {
        $allOffered = [7, 14, 30, 45, 60];

        return [
            ['7,30,60', '', $allOffered, '60', 30, 60, 'a stored default that is still offered wins'],
            ['7,30,60', '', $allOffered, '14', 60, 60, "an unoffered stored default falls to the merchant's default term"],
            ['7,30,60', '', $allOffered, '', 45, 30, 'a merchant default term that is not configured is ignored'],
            ['7,30,60', '', $allOffered, '', null, 30, '30 is preferred over a shorter offered term'],
            ['7,14', '', $allOffered, '', null, 7, 'without 30 offered the shortest offered term is used'],
            ['7,30', '', [7], '', null, 7, '30 configured but not offered by the merchant is not it'],
            ['', '45', $allOffered, '', null, 45, 'an offered custom day is the only configured term'],
            ['', '90', $allOffered, '', null, 0, 'a custom day the merchant does not offer leaves no term'],
            ['7,30', '', [], '', null, 0, 'an unresolvable merchant record leaves no term'],
            ['', '', $allOffered, '30', 30, 0, 'nothing configured leaves no term, whatever is stored'],
        ];
    }

    /**
     * @param array<string, string> $params
     * @dataProvider scopeProvider
     */
    public function testTheRecordIsReadForTheScopeBeingEdited(
        array $params,
        ?int $expectedScopeId,
        string $expectedScope,
        string $case
    ): void {
        $this->config = [
            'payment/two_payment/payment_terms' => '30,60',
            'payment/two_payment/default_payment_term' => '',
        ];

        $this->block($params, [30, 60], null)->getDefaultTerm();

        $this->assertSame([$expectedScopeId, $expectedScope], $this->recordScope, $case);
    }

    public static function scopeProvider(): array
    {
        return [
            [['store' => 'de'], self::STORE_ID, 'store', 'the store param names the store whose record is read'],
            [[], null, 'default', 'no param is the default scope'],
            [['website' => 'eu'], self::WEBSITE_ID, 'website', "a website reads its own key, not a child store's"],
            [['store' => 'broken'], null, 'default', 'an unresolvable store falls back rather than throwing'],
        ];
    }
}
