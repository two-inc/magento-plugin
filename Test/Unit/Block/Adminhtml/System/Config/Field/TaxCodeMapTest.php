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

/**
 * TWO-24877: the tax code mapping field.
 */
class TaxCodeMapTest extends TestCase
{
    private const NAME = 'groups[order_management][fields][tax_code_map][value]';

    private const CODES = [
        ['code' => 'ES_IVA_EXPORT', 'name' => 'Exportación', 'rate' => '0'],
        ['code' => 'ES_IVA_STANDARD', 'name' => 'IVA general', 'rate' => '0.21'],
    ];

    public function testOneRowPerClassIncludingNoneMarkingTheShippingClass(): void
    {
        $html = $this->render(self::CODES, null, '{"0":"ES_IVA_EXPORT"}');

        $this->assertStringContainsString('<td>None (shipping)</td>', $html, 'shipping on None marks the None row');
        $this->assertStringContainsString('<td>Taxable Goods</td>', $html);
        $this->assertMatchesRegularExpression(
            '~name="' . preg_quote(self::NAME, '~') . '\[0\]">.*?<option value="ES_IVA_EXPORT" selected="selected">~s',
            $html,
            'the None row shows its saved code'
        );
        $this->assertStringContainsString('<option value="">(none)</option>', $html);
        $this->assertStringContainsString('ES_IVA_STANDARD IVA general (21%)', $html);
    }

    public function testASavedCodeTheListNoLongerHasStaysSelectable(): void
    {
        $html = $this->render(self::CODES, 2, '{"2":"ES_IVA_RETIRED"}');

        $this->assertStringContainsString('<td>Taxable Goods (shipping)</td>', $html);
        $this->assertStringContainsString('<option value="ES_IVA_RETIRED" selected="selected">ES_IVA_RETIRED</option>', $html);
    }

    public function testAnUnreadableListKeepsTheSavedMappingAsHiddenInputs(): void
    {
        $html = $this->render(null, 2, '{"2":"ES_IVA_EXPORT","0":"ES_IVA_EXEMPT_ART20"}');

        $this->assertStringContainsString('could not be loaded', $html);
        $this->assertStringNotContainsString('<select', $html);
        $this->assertStringContainsString('<input type="hidden" name="' . self::NAME . '[0]" value="ES_IVA_EXEMPT_ART20"/>', $html);
        $this->assertStringContainsString('<input type="hidden" name="' . self::NAME . '[2]" value="ES_IVA_EXPORT"/>', $html);
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

        $block = new TaxCodeMap($context, $storeManager, $records, $taxCodes, $classes, $config);
        $element = new AbstractElement();
        $element->setData('name', self::NAME);
        $element->setData('value', $saved);
        $method = new \ReflectionMethod($block, '_getElementHtml');
        $method->setAccessible(true);

        // Attribute escaping encodes the brackets in the field name; a browser decodes them.
        return html_entity_decode((string)$method->invoke($block, $element), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
