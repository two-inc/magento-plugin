<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Model\Config;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Tax\Api\Data\TaxClass;
use Magento\Tax\Api\TaxClassRepositoryInterface;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Model\Config\NeverTaxedTreatment;
use Two\Gateway\Service\Order\SurchargeTaxCalculator;

/**
 * The one decision point shared by the option source, the field renderer and
 * the save guard. If these three ever disagree, a scope can be warned about
 * but savable, or savable but not warned about.
 */
class NeverTaxedTreatmentTest extends TestCase
{
    /** @var TaxClassRepositoryInterface|\PHPUnit\Framework\MockObject\MockObject */
    private $taxClassRepository;

    /** @var NeverTaxedTreatment */
    private $subject;

    protected function setUp(): void
    {
        $this->taxClassRepository = $this->createMock(TaxClassRepositoryInterface::class);
        $this->subject = new NeverTaxedTreatment($this->taxClassRepository);
    }

    private function stubClassName(int $id, string $name): void
    {
        // Real DataObject-backed stub (Test/Stubs/TaxEngine.php), as
        // SurchargeTaxCalculatorTest uses — getClassName() has to actually
        // work, and the bootstrap's catch-all stub is method-less.
        $this->taxClassRepository->method('get')->with($id)
            ->willReturn(new TaxClass(['className' => $name]));
    }

    public function testCoreNoneIsNeverTaxed(): void
    {
        $this->taxClassRepository->expects($this->never())->method('get');

        $this->assertTrue($this->subject->isNeverTaxed('0'));
    }

    /**
     * Numeric comparison, not a string one: Repository::getSurchargeTaxClassId()
     * int-casts, so all of these mean class id 0 downstream. A string
     * comparison would let them render an option the save then refuses.
     *
     * @dataProvider numericZeroVariants
     */
    public function testNumericVariantsOfZeroAreNeverTaxed(string $value): void
    {
        $this->assertTrue($this->subject->isNeverTaxed($value));
    }

    public static function numericZeroVariants(): array
    {
        return [['0'], ['0.0'], [' 0'], ['0 '], ['00'], ['0e0']];
    }

    public function testTheProvisionedNoTaxClassIsNeverTaxedByName(): void
    {
        $this->stubClassName(4, SurchargeTaxCalculator::NO_TAX_CLASS_NAME);

        $this->assertTrue($this->subject->isNeverTaxed('4'));
    }

    public function testARealTaxClassIsNotNeverTaxed(): void
    {
        $this->stubClassName(2, 'Taxable Goods');

        $this->assertFalse($this->subject->isNeverTaxed('2'));
    }

    /**
     * Equality, not a substring match — a merchant class merely containing the
     * name is a different class and must stay usable.
     */
    public function testAClassWhoseNameMerelyContainsTheProvisionedNameIsNotNeverTaxed(): void
    {
        $this->stubClassName(5, SurchargeTaxCalculator::NO_TAX_CLASS_NAME . ' (legacy)');

        $this->assertFalse($this->subject->isNeverTaxed('5'));
    }

    /**
     * Unselected is not never-taxed: that is the placeholder, handled by
     * assertTaxTreatmentSelected, and reporting it here would put a
     * misleading message on the field.
     *
     * @dataProvider notATreatmentId
     */
    public function testNonNumericAndEmptyValuesAreNotNeverTaxed(string $value): void
    {
        $this->taxClassRepository->expects($this->never())->method('get');

        $this->assertFalse($this->subject->isNeverTaxed($value));
    }

    public static function notATreatmentId(): array
    {
        return [[''], ['  '], ['custom'], ['abc']];
    }

    /**
     * A deleted class is a DIFFERENT failure (SurchargeTaxCalculator logs it
     * at checkout), and one caller is a rendering path — an exception here
     * would blank the admin page.
     */
    public function testAnUnresolvableClassIdIsNotNeverTaxedAndDoesNotThrow(): void
    {
        $this->taxClassRepository->method('get')
            ->willThrowException(new NoSuchEntityException(__('gone')));

        $this->assertFalse($this->subject->isNeverTaxed('99'));
    }

    /**
     * Any LocalizedException, not only NoSuchEntityException — the repository
     * can fail for other reasons and the admin page must still render.
     */
    public function testAnyLocalizedExceptionFromTheRepositoryIsSwallowed(): void
    {
        $this->taxClassRepository->method('get')
            ->willThrowException(new LocalizedException(__('boom')));

        $this->assertFalse($this->subject->isNeverTaxed('99'));
    }
}
