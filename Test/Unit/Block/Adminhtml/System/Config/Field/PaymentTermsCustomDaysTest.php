<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Block\Adminhtml\System\Config\Field;

use Magento\Backend\Block\Template\Context;
use Magento\Framework\Data\Form\Element\AbstractElement;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Block\Adminhtml\System\Config\Field\PaymentTermsCustomDays;

/**
 * The deprecated custom term is offered as keep-or-remove, never as free entry: the merchant
 * cannot type a replacement the save would then refuse (ABN-522).
 *
 * The real _getElementHtml() body runs: Test/Stubs/AdminConfigField.php supplies the framework
 * base class and element, and the test subclass only exposes the protected method.
 */
class PaymentTermsCustomDaysTest extends TestCase
{
    private function render(array $elementData): string
    {
        $block = new class ($this->createMock(Context::class)) extends PaymentTermsCustomDays {
            public function renderForTest(AbstractElement $element): string
            {
                return $this->_getElementHtml($element);
            }
        };

        return $block->renderForTest(new AbstractElement($elementData + [
            'html_id' => 'two_payment_payment_terms_payment_terms_duration_days',
            'name' => 'groups[payment_terms][fields][payment_terms_duration_days][value]',
        ]));
    }

    /**
     * @dataProvider markupProvider
     */
    public function testMarkup(array $elementData, string $needle, bool $expected, string $case): void
    {
        $this->assertSame($expected, str_contains($this->render($elementData), $needle), $case);
    }

    public static function markupProvider(): array
    {
        return [
            [['value' => '37'], '<option value="37" selected="selected">37 days</option>', true, 'the stored term is the selection'],
            [['value' => '37'], '<option value="">Remove</option>', true, 'removal is the only alternative'],
            [['value' => '37'], '<input', false, 'no free-text entry is offered'],
            [['value' => ' 37 '], 'value="37"', true, 'a hand-edited value is trimmed into the option'],
            [['value' => ''], '<option value="" selected="selected">Remove</option>', true, 'nothing stored leaves only removal'],
            [['value' => '"><script>x</script>'], '<script>', false, 'the stored value is escaped'],
            [['value' => '37'], 'disabled', false, 'an editable scope posts the value back'],
            [['value' => '37', 'disabled' => true], 'disabled="disabled"', true, 'an inherited scope is not editable'],
            [
                ['value' => '37'],
                'name="groups[payment_terms][fields][payment_terms_duration_days][value]"',
                true,
                'the select posts under the field\'s own name',
            ],
            [
                ['value' => '37'],
                'id="two_payment_payment_terms_payment_terms_duration_days"',
                true,
                'the id the admin scripts read the term from is preserved',
            ],
        ];
    }
}
