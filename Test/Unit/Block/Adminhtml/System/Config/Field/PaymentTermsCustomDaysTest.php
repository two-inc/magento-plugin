<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Block\Adminhtml\System\Config\Field;

use Magento\Backend\Block\Template\Context;
use Magento\Framework\Data\Form\Element\AbstractElement;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Block\Adminhtml\System\Config\Field\PaymentTermsCustomDays;
use Two\Gateway\Model\Config\Backend\PaymentTerms\OfferedTermsGuard;
use Two\Gateway\Service\Merchant\SettingsProvider;

/**
 * The deprecated custom term is offered as keep-or-remove, never as free entry: the merchant
 * cannot type a replacement the save would then refuse. The fold-in marker is emitted here
 * rather than decided in the browser, so one normalisation governs gate, render and save
 * (ABN-522).
 *
 * The real _getElementHtml() body runs: Test/Stubs/AdminConfigField.php supplies the framework
 * base class and element, and the test subclass only exposes the protected method.
 */
class PaymentTermsCustomDaysTest extends TestCase
{
    /** @param int[] $offered */
    private function render(array $elementData, array $offered = []): string
    {
        $settingsProvider = $this->createMock(SettingsProvider::class);
        $settingsProvider->method('getAvailableTerms')->willReturn($offered);

        $block = new class (
            $this->createMock(Context::class),
            new OfferedTermsGuard($settingsProvider)
        ) extends PaymentTermsCustomDays {
            public function renderForTest(AbstractElement $element): string
            {
                return $this->_getElementHtml($element);
            }
        };

        return $block->renderForTest(new AbstractElement($elementData + [
            'html_id' => 'two_payment_payment_terms_payment_terms_duration_days',
            'name' => 'groups[payment_terms][fields][payment_terms_duration_days][value]',
            'form' => null,
        ]));
    }

    /**
     * @param int[] $offered
     * @dataProvider markupProvider
     */
    public function testMarkup(
        array $elementData,
        array $offered,
        string $needle,
        bool $expected,
        string $case
    ): void {
        $this->assertSame($expected, str_contains($this->render($elementData, $offered), $needle), $case);
    }

    public static function markupProvider(): array
    {
        $marker = 'class="two-legacy-term-folds-in"';

        return [
            [['value' => '37'], [], '<option value="37" selected="selected">37 days</option>', true, 'the stored term is the selection'],
            [['value' => '37'], [], '<option value="">Remove</option>', true, 'removal is the only alternative'],
            [['value' => '37'], [], '<input', false, 'no free-text entry is offered'],
            [['value' => ' 37 '], [], 'value="37"', true, 'a hand-edited value is trimmed into the option'],
            [['value' => '030'], [], '>30 days<', true, 'the label names the normalised term'],
            [['value' => 'abc'], [], '<option value="abc" selected="selected">abc</option>', true, 'an unusable value is shown verbatim so it can be removed'],
            [['value' => ''], [], '<option value="" selected="selected">Remove</option>', true, 'nothing stored leaves only removal'],
            [['value' => '"><script>x</script>'], [], '<script>', false, 'the stored value is escaped'],
            [['value' => '37'], [], 'disabled', false, 'an editable scope posts the value back'],
            [['value' => '37', 'disabled' => true], [], 'disabled="disabled"', true, 'an inherited scope is not editable'],
            [['value' => '37'], [14, 30], $marker, false, 'a term the record does not offer keeps the row visible'],
            [['value' => '30'], [14, 30], $marker, true, 'a term the record offers folds in, so the row hides'],
            [['value' => '030'], [14, 30], $marker, true, 'a leading-zero value folds into the same term'],
            [['value' => '30'], [], $marker, false, 'an unresolvable offered set folds nothing in'],
            [['value' => 'abc'], [14, 30], $marker, false, 'an unusable value has no term to fold into'],
            [
                ['value' => '37'],
                [],
                'name="groups[payment_terms][fields][payment_terms_duration_days][value]"',
                true,
                'the select posts under the field\'s own name',
            ],
            [
                ['value' => '37'],
                [],
                'id="two_payment_payment_terms_payment_terms_duration_days"',
                true,
                'the id the admin scripts read the term from is preserved',
            ],
        ];
    }

    public function testTheOfferedSetIsResolvedForTheStoreScopeBeingEdited(): void
    {
        $settingsProvider = $this->createMock(SettingsProvider::class);
        $settingsProvider->expects($this->once())
            ->method('getAvailableTerms')
            ->with(5)
            ->willReturn([30]);

        $block = new class (
            $this->createMock(Context::class),
            new OfferedTermsGuard($settingsProvider)
        ) extends PaymentTermsCustomDays {
            public function renderForTest(AbstractElement $element): string
            {
                return $this->_getElementHtml($element);
            }
        };

        $form = new class {
            public function getScope(): string
            {
                return 'stores';
            }

            public function getScopeId(): int
            {
                return 5;
            }
        };

        $html = $block->renderForTest(new AbstractElement([
            'value' => '30',
            'html_id' => 'two_payment_payment_terms_payment_terms_duration_days',
            'name' => 'groups[payment_terms][fields][payment_terms_duration_days][value]',
            'form' => $form,
        ]));

        $this->assertStringContainsString('class="two-legacy-term-folds-in"', $html);
    }
}
