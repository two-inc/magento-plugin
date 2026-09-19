<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Model\Config\Backend;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\Context;
use Magento\Framework\Registry;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Model\Config\Backend\ProductButtonEnabled;

/**
 * TWO-25800: the opt-in refuses a value nothing understands at SAVE, where the
 * merchant can see the refusal.
 *
 * The runtime read refuses it too, and must keep doing so — `config:set`, an
 * import and a hand-edited row never instantiate a backend model. This is the
 * early warning, not the guarantee, and the two are not redundant.
 */
class ProductButtonEnabledTest extends TestCase
{
    private function model(array $data): ProductButtonEnabled
    {
        return new ProductButtonEnabled(
            $this->getMockBuilder(Context::class)->disableOriginalConstructor()->getMock(),
            $this->getMockBuilder(Registry::class)->disableOriginalConstructor()->getMock(),
            $this->createMock(ScopeConfigInterface::class),
            $this->createMock(TypeListInterface::class),
            null,
            null,
            $data
        );
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function acceptedProvider(): array
    {
        return [
            'off' => ['0'],
            'on' => ['1'],
        ];
    }

    /**
     * @dataProvider acceptedProvider
     */
    public function testTheYesnoValuesAreStored(string $submitted): void
    {
        $model = $this->model(['value' => $submitted]);
        $model->beforeSave();

        $this->assertSame($submitted, $model->getValue());
    }

    /**
     * "Use Default" is Magento's inherit path and retires the row rather than
     * storing anything, so there is nothing to judge.
     */
    public function testTheInheritPathIsNotRefused(): void
    {
        $model = $this->model(['value' => '', 'inherit' => '1']);
        $model->beforeSave();

        $this->assertSame('', $model->getValue());
    }

    /**
     * An empty string arriving any other way is a submitted value, not an
     * absence — and the read path refuses it, so accepting it here would store
     * something that later withholds the button with only a log to say why.
     */
    public function testAnExplicitEmptySubmissionIsRefused(): void
    {
        $this->expectException(LocalizedException::class);

        $this->model(['value' => ''])->beforeSave();
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function refusedProvider(): array
    {
        return [
            'arbitrary text' => ['garbage'],
            'a truthy-looking word' => ['yes'],
            'a number outside the set' => ['2'],
        ];
    }

    /**
     * @dataProvider refusedProvider
     */
    public function testAnUnrecognisedValueIsRefused(string $submitted): void
    {
        $this->expectException(LocalizedException::class);

        $this->model(['value' => $submitted])->beforeSave();
    }

    /**
     * A crafted POST can send this field as an array. Casting one to string
     * raises an array-to-string warning that Magento's error handler turns into
     * an unexpected exception, so the shape is judged before the value and the
     * merchant still gets the field's own refusal.
     *
     * @return array<string, array{0: mixed}>
     */
    public static function nonScalarProvider(): array
    {
        return [
            'an array' => [['1']],
            'a nested array' => [['on' => true]],
            'an object' => [new \stdClass()],
        ];
    }

    /**
     * @dataProvider nonScalarProvider
     * @param mixed $submitted
     */
    public function testANonScalarSubmissionIsRefusedWithoutCasting($submitted): void
    {
        $this->expectException(LocalizedException::class);

        $this->model(['value' => $submitted])->beforeSave();
    }
}
