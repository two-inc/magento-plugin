<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Model\Config\Backend;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\Context;
use Magento\Framework\Registry;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Model\Config\Backend\PaymentTermsCustomDays;

/**
 * Save-time rules for the deprecated "Custom payment terms (days)": the stored value may be
 * removed but never replaced, and a save that leaves it alone must not disturb it (ABN-522).
 */
class PaymentTermsCustomDaysTest extends TestCase
{
    private function buildModel(string $posted, ?string $stored, array $data = []): PaymentTermsCustomDays
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturn($stored);

        return new PaymentTermsCustomDays(
            $this->getMockBuilder(Context::class)->disableOriginalConstructor()->getMock(),
            $this->getMockBuilder(Registry::class)->disableOriginalConstructor()->getMock(),
            $scopeConfig,
            $this->createMock(TypeListInterface::class),
            null,
            null,
            $data + [
                'value' => $posted,
                'path' => 'payment/two_payment/payment_terms_duration_days',
                'scope' => 'default',
                'scope_id' => 0,
            ]
        );
    }

    /**
     * @dataProvider acceptedValueProvider
     */
    public function testAcceptedValue(string $posted, ?string $stored, string $expected, string $case): void
    {
        $model = $this->buildModel($posted, $stored);

        $model->beforeSave();

        $this->assertSame($expected, $model->getValue(), $case);
    }

    public static function acceptedValueProvider(): array
    {
        return [
            ['30', '30', '30', 'a save posting the stored value back leaves it byte-identical'],
            ['  30  ', '30', '30', 'whitespace around the posted value is not a change'],
            ['', '30', '', 'clearing the field removes the term'],
            ['', null, '', 'nothing stored and nothing posted'],
            ['0', '0', '0', 'a stored zero is a value like any other'],
        ];
    }

    /**
     * @dataProvider refusedValueProvider
     */
    public function testARewriteIsRefused(string $posted, ?string $stored, string $case): void
    {
        $model = $this->buildModel($posted, $stored);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Custom payment terms (days) can only be removed, not changed.');
        $model->beforeSave();
        $this->fail($case);
    }

    public static function refusedValueProvider(): array
    {
        return [
            ['45', '30', 'a different term is refused rather than stored'],
            ['0', '30', 'zeroing is not the removal route'],
            ['37', null, 'a value where none is stored is refused'],
        ];
    }

    public function testTheStoredValueIsReadAtTheScopeBeingSaved(): void
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->expects($this->once())
            ->method('getValue')
            ->with('payment/two_payment/payment_terms_duration_days', 'stores', 'de')
            ->willReturn('30');

        $model = new PaymentTermsCustomDays(
            $this->getMockBuilder(Context::class)->disableOriginalConstructor()->getMock(),
            $this->getMockBuilder(Registry::class)->disableOriginalConstructor()->getMock(),
            $scopeConfig,
            $this->createMock(TypeListInterface::class),
            null,
            null,
            [
                'value' => '30',
                'path' => 'payment/two_payment/payment_terms_duration_days',
                'scope' => 'stores',
                'scope_id' => 5,
                'scope_code' => 'de',
            ]
        );

        $model->beforeSave();

        $this->assertSame('30', $model->getValue());
    }
}
