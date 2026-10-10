<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Block\Adminhtml\System\Config\Field;

use Magento\Backend\Block\Template\Context;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Tax\Model\TaxClass\Source\Product as ProductTaxClassSource;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\Config\RepositoryInterface as ConfigRepository;
use Two\Gateway\Block\Adminhtml\System\Config\Field\TaxCodeMap;
use Two\Gateway\Service\Api\TaxCodes;
use Two\Gateway\Service\Merchant\RecordProvider;
use Two\Gateway\Service\Order\ZeroTaxRates;

/**
 * TWO-24877, TWO-26153: the tax code mapping field.
 */
class TaxCodeMapTest extends TestCase
{
    private const NAME = 'groups[order_management][fields][tax_code_map][value]';

    private const CODES = [
        ['code' => 'ES_IVA_EXPORT', 'name' => 'Exportación', 'rate' => '0'],
        ['code' => 'ES_IVA_STANDARD', 'name' => 'IVA general', 'rate' => '0.21'],
    ];

    /**
     * TWO-26153: each class has an exempt-buyer row, a row per 0% rate its rules use, and a no-rule row.
     */
    public function testEachClassHasAnExemptRowItsZeroRateRowsAndANoRuleRow(): void
    {
        $html = $this->render(self::CODES, null, '{"2|rate:ES-CANARIAS-0":"ES_IVA_EXPORT"}');

        $this->assertStringContainsString('<td rowspan="2">None (shipping)</td>', $html, 'class None has no rates; shipping on None marks it');
        $this->assertStringContainsString('<td rowspan="4">Taxable Goods</td>', $html);
        foreach (['Buyer in another EU country with a VAT number', 'ES-CANARIAS-0 (ES, 35*)', 'ES-CEUTA-0 (ES)', 'No rule for the address'] as $label) {
            $this->assertStringContainsString('<td>' . $label . '</td>', $html, $label);
        }
        $this->assertMatchesRegularExpression(
            '~data-key="2\|rate:ES-CANARIAS-0">.*?<option value="ES_IVA_EXPORT" selected="selected">~s',
            $html,
            'the rate row shows its saved code'
        );
        $this->assertStringContainsString('<option value="">(none)</option>', $html);
        $this->assertStringContainsString('ES_IVA_STANDARD IVA general (21%)', $html);
        $this->assertSame(6, substr_count($html, '<select'), 'two rows for None, four for Taxable Goods');
        $this->assertSame(1, substr_count($html, 'name="'), 'the map posts as one field, whatever the number of rows');
        $this->assertStringContainsString(
            '<input type="hidden" id="tax_code_map" name="' . self::NAME . '" value="{"2|rate:ES-CANARIAS-0":"ES_IVA_EXPORT"}" data-carried="{}"/>',
            $html,
            'the field starts as the saved map, so a save without the script keeps it'
        );
        $this->assertStringContainsString(
            '<script type="text/x-magento-init">{"#tax_code_map_rows":{"Two_Gateway/js/tax-code-map":{"input":"tax_code_map"}}}</script>',
            $html
        );
    }

    /**
     * TWO-26244: a rated display name already ends in its rate, so the label carries the rate once.
     *
     * @dataProvider labels
     */
    public function testTheLabelShowsTheRateOnce(string $name, string $rate, string $label, string $description): void
    {
        $html = $this->render([['code' => 'C', 'name' => $name, 'rate' => $rate]], null, '{}');

        $this->assertStringContainsString('<option value="C">' . $label . '</option>', $html, $description);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function labels(): array
    {
        return [
            'rated' => ['IVA General (21%)', '0.21', 'C IVA General (21%)', 'a rated name is not given the rate again'],
            'trailing space' => ['IVA Reducido (10%)  ', '0.1', 'C IVA Reducido (10%)', 'trailing space does not hide the rate'],
            'spaced rate' => ['IVA Superreducido (4 %)', '0.04', 'C IVA Superreducido (4 %)', 'a spaced rate counts as a rate'],
            'unrated' => ['Exento', '0', 'C Exento (0%)', 'an unrated name gets the rate'],
            'bracket without %' => ['Inversión del sujeto pasivo (art. 84)', '0', 'C Inversión del sujeto pasivo (art. 84) (0%)', 'a bracket without % is not a rate'],
            'rate mid-name' => ['IVA (21%) general', '0.21', 'C IVA (21%) general (21%)', 'a rate mid-name is not the trailing rate'],
            'no name' => ['', '0.21', 'C (21%)', 'no name still shows the rate'],
            'no rate' => ['Exento', '', 'C Exento', 'no rate appends nothing'],
        ];
    }

    public function testASavedCodeTheListNoLongerHasStaysSelectable(): void
    {
        $html = $this->render(self::CODES, 2, '{"2|none":"ES_IVA_RETIRED"}');

        $this->assertStringContainsString('<td rowspan="4">Taxable Goods (shipping)</td>', $html);
        $this->assertStringContainsString('<option value="ES_IVA_RETIRED" selected="selected">ES_IVA_RETIRED</option>', $html);
    }

    public function testAnUnreadableListKeepsTheSavedMappingInTheField(): void
    {
        $html = $this->render(null, 2, '{"2|none":"ES_IVA_EXPORT","0|exempt":"ES_IVA_EXEMPT_ART20"}');

        $this->assertStringContainsString('could not be loaded', $html);
        $this->assertStringNotContainsString('<select', $html);
        $this->assertStringContainsString('name="' . self::NAME . '" value="{"0|exempt":"ES_IVA_EXEMPT_ART20","2|none":"ES_IVA_EXPORT"}"', $html);
    }

    public function testASavedRowTheFormNoLongerShowsIsCarried(): void
    {
        $html = $this->render(self::CODES, null, '{"2|rate:ES-GONE-0":"ES_IVA_EXPORT","2|none":"ES_IVA_EXPORT"}');

        $this->assertStringContainsString('data-carried="{"2|rate:ES-GONE-0":"ES_IVA_EXPORT"}"', $html);
    }

    private function render(?array $codes, ?int $shippingClass, string $saved): string
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
        $records = $this->createMock(RecordProvider::class);
        $records->method('getRecord')->willReturn(['country_code' => 'ES']);
        $taxCodes = $this->createMock(TaxCodes::class);
        $taxCodes->method('getSelectable')->willReturn($codes);
        $classes = $this->createMock(ProductTaxClassSource::class);
        $classes->method('getAllOptions')->willReturn([
            ['value' => '0', 'label' => 'None'],
            ['value' => '2', 'label' => 'Taxable Goods'],
        ]);
        $config = $this->createMock(ConfigRepository::class);
        $config->method('getShippingTaxClassId')->willReturn($shippingClass);

        $zeroRates = $this->createMock(ZeroTaxRates::class);
        $zeroRates->method('byClass')->willReturn([2 => [
            ['code' => 'ES-CANARIAS-0', 'country' => 'ES', 'postcode' => '35*'],
            ['code' => 'ES-CEUTA-0', 'country' => 'ES', 'postcode' => '*'],
        ]]);

        $block = new TaxCodeMap($context, $storeManager, $records, $taxCodes, $classes, $config, $zeroRates);
        $element = new AbstractElement();
        $element->setData('name', self::NAME);
        $element->setData('html_id', 'tax_code_map');
        $element->setData('value', $saved);
        $method = new \ReflectionMethod($block, '_getElementHtml');
        $method->setAccessible(true);

        // Attribute escaping encodes the brackets in the field name; a browser decodes them.
        return html_entity_decode((string)$method->invoke($block, $element), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
