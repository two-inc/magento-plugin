<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Block\Adminhtml\System\Config\Field;

use Magento\Backend\Block\Template\Context;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Framework\Locale\ResolverInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Tax\Model\TaxClass\Source\Product as ProductTaxClassSource;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\BrandRegistryInterface;
use Two\Gateway\Api\Config\RepositoryInterface as ConfigRepository;
use Two\Gateway\Block\Adminhtml\System\Config\Field\TaxCodeMap;
use Two\Gateway\Model\Config\AdminScope;
use Two\Gateway\Model\Config\TaxCodeSectionResolver;
use Two\Gateway\Service\Order\ZeroTaxRates;

/**
 * TWO-24877, TWO-26153: the tax code section as the merchant sees it.
 */
class TaxCodeMapTest extends TestCase
{
    private const NAME = 'groups[tax][fields][tax_code_map][value]';

    /** The admin's name for ES; the ISO code where PHP cannot name it (no intl). */
    private static function spain(): string
    {
        return class_exists(\Locale::class, false) ? 'Spain' : 'ES';
    }

    private const CODES = [
        ['code' => 'ES_IVA_EXPORT', 'name' => 'Exportación', 'rate' => '0'],
        ['code' => 'ES_IVA_INTRA_COMMUNITY_SERVICES', 'name' => 'Intracomunitaria', 'rate' => '0'],
        ['code' => 'ES_IVA_STANDARD', 'name' => 'IVA general', 'rate' => '0.21'],
    ];

    public function testTheTableHasARowPerClassAndTheCasesAsColumns(): void
    {
        $html = $this->render(
            self::state('rows'),
            '{"2|rate:ES-CANARIAS-0":"ES_IVA_EXPORT","2|none":"ES_IVA_STANDARD","0|exempt":"ES_IVA_RETIRED","2|rate:ES-GONE-0":"ES_IVA_EXPORT"}'
        );

        $this->assertStringContainsString('<h4 class="two-tax-code-section__title">Tax codes for 0% lines</h4>', $html, 'the title is a heading');
        $this->assertStringContainsString('Two requires a tax code on every 0% line for your country (' . self::spain() . ')', $html);
        foreach (['Tax class', 'VAT-registered buyer, other EU state', 'Your 0% tax rates', 'Buyer\'s address does not map to a tax rule (no tax charged, e.g. exports)'] as $column) {
            $this->assertStringContainsString('<th scope="col">' . $column . '</th>', $html, $column);
        }
        $this->assertStringContainsString('<th scope="row">None (shipping)</th>', $html, 'shipping on None marks it');
        $this->assertStringContainsString('<th scope="row">Taxable Goods</th>', $html);
        $this->assertStringContainsString('No 0% tax rate in this class', $html, 'None has no 0% rate');
        $this->assertStringContainsString('<div class="note">ES-CANARIAS-0 (ES, 35*)</div>', $html);
        $this->assertStringContainsString('<div class="note">ES-CEUTA-0 (ES)</div>', $html);
        $this->assertSame(6, substr_count($html, '<select'), 'None: exempt and no rule; Taxable Goods: exempt, two rates, no rule');
        $this->assertSame(1, substr_count($html, 'name="'), 'the map posts as one field, whatever the number of rows');
        $this->assertMatchesRegularExpression('~data-key="2\|rate:ES-CANARIAS-0"[^>]*>.*?<option value="ES_IVA_EXPORT" selected="selected">ES_IVA_EXPORT - Exportación</option>~s', $html);
        $this->assertStringNotContainsString('<option value="ES_IVA_STANDARD">', $html, 'a code above 0% is not offered');
        $this->assertStringContainsString('<option value="ES_IVA_STANDARD" selected="selected">ES_IVA_STANDARD (not valid on 0% lines)</option>', $html, 'a saved code above 0% stays, marked');
        $this->assertStringContainsString('<option value="ES_IVA_RETIRED" selected="selected">ES_IVA_RETIRED (no longer listed)</option>', $html, 'a saved unlisted code stays, marked');
        $this->assertStringContainsString('ES_IVA_RETIRED: no longer listed</div>', $html, 'the mark is shown beside the select too');
        $this->assertStringContainsString('aria-label="Taxable Goods: Your 0% tax rates: ES-CEUTA-0 (ES)"', $html);
        $this->assertStringContainsString('aria-label="None (shipping): VAT-registered buyer, other EU state" aria-describedby="two-tax-code-marker-', $html);
        $this->assertStringContainsString('min-width:calc(35ch + 3em)', $html, 'wide enough for the longest code');
        $this->assertStringContainsString('data-carried="{"2|rate:ES-GONE-0":"ES_IVA_EXPORT"}"', $html, 'a saved row the table no longer shows is carried');
        $this->assertStringContainsString('<script type="text/x-magento-init">{"#tax_code_map_rows":{"Two_Gateway/js/tax-code-map":{"input":"tax_code_map"}}}</script>', $html);
        $this->assertStringNotContainsString('Clear saved tax codes', $html, 'nothing to clear on a readable map');
    }

    /**
     * @dataProvider views
     * @param string[] $present
     * @param string[] $absent
     */
    public function testEachViewShowsWhatNeedsAttention(array $state, array $present, array $absent, string $description): void
    {
        $html = $this->render($state, '{"2|none":"ES_IVA_EXPORT"}');

        foreach ($present as $text) {
            $this->assertStringContainsString($text, $html, $description);
        }
        foreach ($absent as $text) {
            $this->assertStringNotContainsString($text, $html, $description);
        }
    }

    public static function views(): array
    {
        $clear = '<input type="checkbox" class="admin__control-checkbox" name="' . self::NAME . '" value="__clear__"/> Clear saved tax codes';

        return [
            [self::state('notice', ['N1'], true), ['still apply at checkout, though Two does not require them for your country (' . self::spain() . ')', $clear], ['<select', '<input type="hidden"'], 'N1 with Clear and no table'],
            [self::state('notice', ['N2'], true), ['message-warning', 'Some saved tax codes are not valid on 0% lines for your country (' . self::spain() . '), so Two refuses orders that use them.', $clear], ['<select'], 'N2 with Clear'],
            [self::state('profile_error', ['N3'], true, 503), ['Could not read your merchant details from Two (HTTP 503)', 'message-error', 'Saved tax codes can\'t be read, so Two is hidden at checkout; clear them to restore it.', $clear], ['<select'], 'E1 with N3 and Clear'],
            [['rejected' => true] + self::state('profile_error'), ['Two rejected the stored API key; check API key and Environment.'], ['<select', 'Clear saved'], 'E1b'],
            [self::state('list_error', [], false, 0), ['Could not check whether Two requires tax codes for your country (' . self::spain() . ', HTTP -)'], ['<select'], 'E2 with no status'],
            [self::state('rows', ['N3'], true), ['<select', 'clear them to restore it'], [], 'N3 above the rows'],
            [self::state('rows', ['SCOPE']), ['Some websites or store views below this scope use another API key or environment'], ['Clear saved'], 'the scope notice'],
        ];
    }

    public function testACountryWithNoNameShowsItsCode(): void
    {
        $html = $this->render(['country' => 'XX'] + self::state('notice', ['N1'], true), '{}');

        $this->assertStringContainsString('for your country (XX).', $html);
    }

    /**
     * @dataProvider scopeLabels
     */
    public function testTheHeadingCarriesTheScopeLabelAsCoreRowsDo(array $element, bool $singleStore, string $expected, string $description): void
    {
        $html = $this->render(self::state('rows'), '{}', $element, $singleStore);

        $this->assertStringContainsString('<h4 class="two-tax-code-section__title">Tax codes for 0% lines' . $expected . '</h4>', $html, $description);
    }

    public static function scopeLabels(): array
    {
        $scoped = ['scope' => true, 'scopeLabel' => '[STORE VIEW]'];

        return [
            [$scoped, false, ' <span class="two-tax-code-section__scope" data-config-scope="[STORE VIEW]">[STORE VIEW]</span>', 'a multi-store shop sees the scope'],
            [$scoped, true, '', 'single-store mode shows none, as core'],
            [[], false, '', 'no scope, no label'],
        ];
    }

    public function testAHiddenSectionRendersNoRowSoNothingIsPosted(): void
    {
        $this->assertSame('', $this->render(self::state('hidden'), '{"2|none":"ES_IVA_EXPORT"}'));
    }

    public function testClearComesAfterTheMapSoATickIsWhatPosts(): void
    {
        $html = $this->render(self::state('rows', ['N3'], true), 'junk');

        $this->assertLessThan(strpos($html, 'value="__clear__"'), strpos($html, '<input type="hidden"'));
    }

    public function testAnInheritedValueRendersDisabledBesideItsUseWebsiteBox(): void
    {
        $html = $this->render(self::state('rows', [], true), '{}', ['canUseWebsiteValue' => true, 'inherit' => 1]);

        $this->assertStringContainsString('<td class="use-default"><input id="tax_code_map_inherit" type="checkbox" checked="checked"/></td>', $html);
        $this->assertSame(substr_count($html, '<select') + 2, substr_count($html, 'disabled="disabled"'), 'every select, the field and Clear');
        $this->assertStringStartsWith('<tr id="row_tax_code_map"><td colspan="2" class="value two-tax-code-section">', $html);
    }

    private static function state(string $view, array $notices = [], bool $clear = false, int $http = 0): array
    {
        return ['view' => $view, 'notices' => $notices, 'clear' => $clear, 'country' => 'ES',
            'codes' => self::CODES, 'rejected' => false, 'http' => $http];
    }

    private function render(array $state, string $saved, array $element = [], bool $singleStore = false): string
    {
        $context = new class extends Context {
            public function getRequest()
            {
                return new class {
                    public function getParam($name)
                    {
                        return null;
                    }
                };
            }
        };
        $store = new class {
            public function getId()
            {
                return 1;
            }
        };
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getDefaultStoreView')->willReturn($store);
        $storeManager->method('isSingleStoreMode')->willReturn($singleStore);
        $resolver = $this->createMock(TaxCodeSectionResolver::class);
        $resolver->method('resolve')->willReturn($state);
        $scope = $this->createMock(AdminScope::class);
        $scope->method('fromCodes')->willReturn([null, 'default']);
        $classes = $this->createMock(ProductTaxClassSource::class);
        $classes->method('getAllOptions')->willReturn([
            ['value' => '0', 'label' => 'None'],
            ['value' => '2', 'label' => 'Taxable Goods'],
        ]);
        $config = $this->createMock(ConfigRepository::class);
        $config->method('getShippingTaxClassId')->willReturn(null);
        $zeroRates = $this->createMock(ZeroTaxRates::class);
        $zeroRates->method('byClass')->willReturn([2 => [
            ['code' => 'ES-CANARIAS-0', 'country' => 'ES', 'postcode' => '35*'],
            ['code' => 'ES-CEUTA-0', 'country' => 'ES', 'postcode' => '*'],
        ]]);
        $brand = $this->createMock(BrandRegistryInterface::class);
        $brand->method('getProductName')->willReturn('Two');
        $locale = new class implements ResolverInterface {
            public function getLocale()
            {
                return 'en_GB';
            }
        };

        $block = new TaxCodeMap($context, $storeManager, $resolver, $scope, $classes, $config, $zeroRates, $brand, $locale);
        $field = new AbstractElement();
        $field->setData(['name' => self::NAME, 'html_id' => 'tax_code_map', 'value' => $saved,
            'label' => 'Tax codes for 0% lines'] + $element);

        // Attribute escaping encodes the brackets in the field name; a browser decodes them.
        return html_entity_decode((string)$block->render($field), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
