<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Model\Config\Backend;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\Context;
use Magento\Framework\Registry;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Model\Config\Backend\DefaultPaymentTerm;

/**
 * Save-time rule for "Default payment terms" (ABN-495): a default outside the
 * terms being saved alongside it was stored verbatim and only masked at read
 * time, so the admin screen kept showing a default the checkout never used.
 */
class DefaultPaymentTermTest extends TestCase
{
    private function buildModel(string $value, array $fieldsetData): DefaultPaymentTerm
    {
        return new DefaultPaymentTerm(
            $this->getMockBuilder(Context::class)->disableOriginalConstructor()->getMock(),
            $this->getMockBuilder(Registry::class)->disableOriginalConstructor()->getMock(),
            $this->createMock(ScopeConfigInterface::class),
            $this->createMock(TypeListInterface::class),
            null,
            null,
            ['value' => $value, 'scope' => 'default', 'scope_id' => 0, 'fieldset_data' => $fieldsetData]
        );
    }

    /**
     * @param array<string, mixed> $fieldsetData
     * @dataProvider acceptedProvider
     */
    public function testAnEnabledDefaultIsSaved(string $value, array $fieldsetData, string $case): void
    {
        $model = $this->buildModel($value, $fieldsetData);

        $model->beforeSave();

        $this->assertSame($value, $model->getValue(), $case);
    }

    public static function acceptedProvider(): array
    {
        return [
            ['30', ['payment_terms' => ['14', '30'], 'payment_terms_duration_days' => ''], 'a ticked preset'],
            ['37', ['payment_terms' => ['14'], 'payment_terms_duration_days' => '37'], 'the custom day'],
            ['30', ['payment_terms' => '14,30', 'payment_terms_duration_days' => ''], 'a CSV selection'],
            ['', ['payment_terms' => ['14'], 'payment_terms_duration_days' => ''], 'no choice at all'],
            ['7', [], 'nothing posted to validate against (a CLI config:set)'],
        ];
    }

    /**
     * @param array<string, mixed> $fieldsetData
     * @dataProvider refusedProvider
     */
    public function testADefaultOutsideTheEnabledTermsIsRefused(
        string $value,
        array $fieldsetData,
        string $message,
        string $case
    ): void {
        $model = $this->buildModel($value, $fieldsetData);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage($message);
        $model->beforeSave();
        $this->fail($case);
    }

    public static function refusedProvider(): array
    {
        return [
            [
                '7',
                ['payment_terms' => ['14', '30'], 'payment_terms_duration_days' => ''],
                'Default payment term 7 days is not one of the terms you offer: 14, 30 days.',
                'the refusal names the rejected default and the enabled set',
            ],
            [
                '14',
                ['payment_terms' => [], 'payment_terms_duration_days' => '37'],
                'Default payment term 14 days is not one of the terms you offer: 37 days.',
                'a custom-only selection still constrains the default',
            ],
        ];
    }
}
