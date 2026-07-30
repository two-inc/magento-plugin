<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Block\Adminhtml\System\Config\Field;

use Magento\Backend\Block\Template\Context;
use Magento\Framework\Data\Form\Element\AbstractElement;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Block\Adminhtml\System\Config\Field\SurchargeTaxClass;
use Two\Gateway\Model\Config\NeverTaxedTreatment;

/**
 * The fail-loud half of TWO-25279: a scope still holding a never-taxed
 * treatment is told so on the field itself.
 *
 * Without this the failure is silent and looks like the opposite of what it
 * is — the option is absent from the select, so the field renders on the
 * placeholder and simply looks unset, while the surcharge is in fact being
 * charged untaxed.
 *
 * The real _getElementHtml() body runs: Test/Stubs/AdminConfigField.php
 * supplies the framework base class and element, and the test subclass only
 * exposes the protected method rather than replacing it.
 */
class SurchargeTaxClassTest extends TestCase
{
    /** @var NeverTaxedTreatment|\PHPUnit\Framework\MockObject\MockObject */
    private $neverTaxedTreatment;

    protected function setUp(): void
    {
        $this->neverTaxedTreatment = $this->createMock(NeverTaxedTreatment::class);
    }

    private function render(string $storedValue): AbstractElement
    {
        // The REAL block, not an override of the method under test — only the
        // protected renderer is exposed. Test/Stubs/AdminConfigField.php
        // supplies the framework base class.
        $block = new class (
            $this->createMock(Context::class),
            $this->neverTaxedTreatment
        ) extends SurchargeTaxClass {
            public function renderForTest(AbstractElement $element): string
            {
                return $this->_getElementHtml($element);
            }
        };

        $element = new AbstractElement(['value' => $storedValue]);
        $this->assertSame(
            'element-html',
            $block->renderForTest($element),
            'the renderer must still delegate to the base class for the markup itself'
        );

        return $element;
    }

    public function testNeverTaxedStoredValueSetsALoudComment(): void
    {
        $this->neverTaxedTreatment->method('isNeverTaxed')->with('0')->willReturn(true);

        $comment = (string)$this->render('0')->getComment();

        $this->assertStringContainsString('untaxed in every jurisdiction', $comment);
        $this->assertStringContainsString('can no longer be saved', $comment);
    }

    /**
     * The merchant needs the way OUT, not just the diagnosis — the message
     * names the replacement route and links to Tax Rules.
     */
    public function testTheCommentTellsTheMerchantWhatToDo(): void
    {
        $this->neverTaxedTreatment->method('isNeverTaxed')->willReturn(true);

        $comment = (string)$this->render('0')->getComment();

        $this->assertStringContainsString('0% rate', $comment);
        $this->assertStringContainsString('tax/rule/index', $comment);
    }

    public function testARealTaxClassGetsNoComment(): void
    {
        $this->neverTaxedTreatment->method('isNeverTaxed')->with('2')->willReturn(false);

        $this->assertNull($this->render('2')->getComment());
    }

    /**
     * An unset field must not be accused of anything — that is the
     * placeholder, and assertTaxTreatmentSelected owns it.
     */
    public function testAnUnsetFieldGetsNoComment(): void
    {
        $this->neverTaxedTreatment->method('isNeverTaxed')->with('')->willReturn(false);

        $this->assertNull($this->render('')->getComment());
    }

    /**
     * The decision is delegated to the shared service, so the warning covers
     * exactly the set the save refuses — including the plugin-provisioned
     * class, whose id is merchant-specific.
     */
    public function testTheDecisionIsDelegatedToTheSharedService(): void
    {
        $this->neverTaxedTreatment->expects($this->once())
            ->method('isNeverTaxed')
            ->with('4')
            ->willReturn(true);

        $this->assertNotNull($this->render('4')->getComment());
    }
}
