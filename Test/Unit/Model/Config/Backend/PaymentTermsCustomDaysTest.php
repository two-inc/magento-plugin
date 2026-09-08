<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Model\Config\Backend;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\Context;
use Magento\Framework\Registry;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Model\Config\Backend\PaymentTerms\OfferedTermsGuard;
use Two\Gateway\Model\Config\Backend\PaymentTermsCustomDays;
use Two\Gateway\Service\Merchant\SettingsProvider;

/**
 * Save-time rules for "Custom payment terms (days)": a value the merchant
 * record does not offer is refused (ABN-493), and one that duplicates an
 * offered term — ticked or not, since the available-terms set carries no tick
 * state — is cleared (TWO-25498). The matching fold-in (ticking that term's
 * checkbox) is on the sibling PaymentTermsCheckboxes backend model.
 */
class PaymentTermsCustomDaysTest extends TestCase
{
    /** @param int[] $offeredForStore terms offered at store 5; $offered covers every other scope */
    private function buildModel(array $data, array $offered, array $offeredForStore = null): PaymentTermsCustomDays
    {
        $settingsProvider = $this->createMock(SettingsProvider::class);
        $settingsProvider->method('getAvailableTerms')->willReturnCallback(
            static fn ($storeId) => $storeId === 5 && $offeredForStore !== null ? $offeredForStore : $offered
        );

        return new PaymentTermsCustomDays(
            $this->getMockBuilder(Context::class)->disableOriginalConstructor()->getMock(),
            $this->getMockBuilder(Registry::class)->disableOriginalConstructor()->getMock(),
            $this->createMock(ScopeConfigInterface::class),
            $this->createMock(TypeListInterface::class),
            new OfferedTermsGuard($settingsProvider),
            null,
            null,
            $data
        );
    }

    /**
     * @param int[] $offered
     * @dataProvider savedValueProvider
     */
    public function testSavedValue(string $value, array $offered, string $expected, string $case): void
    {
        $model = $this->buildModel(['value' => $value, 'scope' => 'default', 'scope_id' => 0], $offered);

        $model->beforeSave();

        $this->assertSame($expected, $model->getValue(), $case);
    }

    public static function savedValueProvider(): array
    {
        return [
            ['', [14, 30], '', 'an empty field is left empty'],
            ['0', [14, 30], '0', 'a zero is not a term and is left alone'],
            ['30', [14, 30, 60], '', 'a value duplicating an offered term is cleared'],
            ['37', [], '37', 'an unresolvable offered set cannot refuse, so the value stands'],
        ];
    }

    /**
     * @param int[] $offered
     * @dataProvider refusedValueProvider
     */
    public function testAnUnofferedValueIsRefused(string $value, array $offered, string $message, string $case): void
    {
        $model = $this->buildModel(['value' => $value, 'scope' => 'default', 'scope_id' => 0], $offered);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage($message);
        $model->beforeSave();
        $this->fail($case);
    }

    public static function refusedValueProvider(): array
    {
        return [
            [
                '37',
                [7, 14, 15, 20, 21, 30, 45, 60, 90],
                'Payment terms you are not able to offer: 37 days.'
                . ' Choose from: 7, 14, 15, 20, 21, 30, 45, 60, 90 days.',
                'the refusal names the rejected value and the offered set',
            ],
            [
                '1',
                [30],
                'Payment terms you are not able to offer: 1 days. Choose from: 30 days.',
                'a single-term merchant refuses everything else',
            ],
        ];
    }

    public function testResolvesTheOfferedSetAtTheStoreScopeBeingSaved(): void
    {
        $model = $this->buildModel(
            ['value' => '45', 'scope' => 'stores', 'scope_id' => 5],
            [14, 30],
            [45]
        );

        $model->beforeSave();

        $this->assertSame(
            '',
            $model->getValue(),
            'a store-scope save must resolve available terms for that store, not the default scope'
        );
    }
}
