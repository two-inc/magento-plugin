<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Model\Config\Backend;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Message\ManagerInterface as MessageManager;
use Magento\Framework\Model\Context;
use Magento\Framework\Registry;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Model\Config\Backend\PaymentTerms\OfferedTermsGuard;
use Two\Gateway\Model\Config\Backend\PaymentTermsCustomDays;
use Two\Gateway\Service\Merchant\SettingsProvider;

/**
 * Save-time rules for the deprecated "Custom payment terms (days)": the stored value may be
 * removed but never replaced, a save that leaves it alone must not disturb it, and a value the
 * merchant record now offers as a standard term folds into that term's checkbox (ABN-522).
 */
class PaymentTermsCustomDaysTest extends TestCase
{
    /** @var MessageManager|MockObject */
    private $messageManager;

    protected function setUp(): void
    {
        $this->messageManager = $this->createMock(MessageManager::class);
    }

    /**
     * @param int[] $offered terms the merchant record offers; empty means it did not resolve
     * @param array<string, mixed> $data extra model data, e.g. a scope or a narrower fieldset_data
     */
    private function buildModel(
        string $posted,
        ?string $stored,
        array $offered = [],
        array $data = []
    ): PaymentTermsCustomDays {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturn($stored);

        $settingsProvider = $this->createMock(SettingsProvider::class);
        $settingsProvider->method('getAvailableTerms')->willReturn($offered);

        return new PaymentTermsCustomDays(
            $this->getMockBuilder(Context::class)->disableOriginalConstructor()->getMock(),
            $this->getMockBuilder(Registry::class)->disableOriginalConstructor()->getMock(),
            $scopeConfig,
            $this->createMock(TypeListInterface::class),
            new OfferedTermsGuard($settingsProvider),
            $this->messageManager,
            null,
            null,
            $data + [
                'value' => $posted,
                'path' => 'payment/two_payment/payment_terms_duration_days',
                'scope' => 'default',
                'scope_id' => 0,
                'fieldset_data' => ['payment_terms' => ['14']],
            ]
        );
    }

    /**
     * @param int[] $offered
     * @dataProvider acceptedValueProvider
     */
    public function testAcceptedValue(
        string $posted,
        ?string $stored,
        array $offered,
        string $expected,
        string $case
    ): void {
        $model = $this->buildModel($posted, $stored, $offered);

        $model->beforeSave();

        $this->assertSame($expected, $model->getValue(), $case);
    }

    public static function acceptedValueProvider(): array
    {
        return [
            ['30', '30', [], '30', 'a save posting the stored value back leaves it byte-identical'],
            ['  30  ', '  30  ', [], '30', 'whitespace around the posted value is not a change'],
            ['', '30', [], '', 'clearing the field removes the term'],
            ['', null, [], '', 'nothing stored and nothing posted'],
            ['37', '37', [14, 30], '37', 'a term the merchant record does not offer stays put'],
            ['37', '37', [], '37', 'an unresolvable offered set matches nothing, so nothing is deleted'],
            ['30', '30', [14, 30, 60], '', 'a term the record offers folds into that checkbox and is cleared'],
            ['030', '030', [14, 30], '', 'a leading-zero value folds into the same term'],
            ['', 'abc', [], '', 'removing an unusable value clears the block in one save'],
            ['', '-5', [], '', 'removing a negative clears the block in one save'],
        ];
    }

    /**
     * @param int[] $offered
     * @dataProvider refusedValueProvider
     */
    public function testARefusedSave(
        string $posted,
        ?string $stored,
        array $offered,
        string $message,
        string $case
    ): void {
        $model = $this->buildModel($posted, $stored, $offered);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage($message);
        $model->beforeSave();
        $this->fail($case);
    }

    public static function refusedValueProvider(): array
    {
        $rewrite = 'Custom payment terms (days) can only be removed, not changed.';

        return [
            ['45', '30', [], $rewrite, 'a different term is refused rather than stored'],
            ['0', '30', [], $rewrite, 'zeroing is not the removal route'],
            ['37', null, [], $rewrite, 'a value where none is stored is refused'],
            [
                'abc',
                'abc',
                [],
                'Custom payment terms (days) holds "abc", which is not a usable number of days.'
                . ' Choose Remove on that field to clear it.',
                'an unusable value blocks the save and names the field and the remedy',
            ],
            [
                '30.0',
                '30.0',
                [30],
                'Custom payment terms (days) holds "30.0", which is not a usable number of days.'
                . ' Choose Remove on that field to clear it.',
                'a decimal blocks the save even where it names an offered term',
            ],
        ];
    }

    /**
     * The matching tick is the sibling field's write. Where the post carries no value for it —
     * its own scope inherits, or env.php locks it — clearing here would drop the term outright.
     *
     * @param array<string, mixed> $fieldsetData
     * @dataProvider siblingPresenceProvider
     */
    public function testTheFoldInNeedsTheSiblingWriteInTheSameSave(
        array $fieldsetData,
        string $expected,
        string $case
    ): void {
        $model = $this->buildModel('30', '30', [14, 30], ['fieldset_data' => $fieldsetData]);

        $model->beforeSave();

        $this->assertSame($expected, $model->getValue(), $case);
    }

    public static function siblingPresenceProvider(): array
    {
        return [
            [['payment_terms' => ['14']], '', 'the sibling is posted, so the fold-in clears this field'],
            [['payment_terms' => '14,30'], '', 'a CSV post of the sibling counts too'],
            [['payment_terms' => ''], '', 'every box unticked is still a posted sibling'],
            [[], '30', 'the sibling absent from the post takes no term, so nothing is cleared'],
            [['payment_terms' => null], '30', 'an inherited or locked sibling posts no value'],
            [
                ['payment_terms_duration_days' => '30'],
                '30',
                'only this field in the post is not enough to move the term',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $fieldsetData
     * @dataProvider announcementProvider
     */
    public function testTheFoldInIsAnnouncedOnlyOnceTheSaveCommits(
        string $posted,
        array $offered,
        array $fieldsetData,
        bool $expectNotice,
        string $case
    ): void {
        $this->messageManager->expects($expectNotice ? $this->once() : $this->never())
            ->method('addNoticeMessage')
            ->with($this->callback(static fn ($message): bool => str_contains(
                (string)$message,
                'Custom payment terms (days) of 30 is now one of the standard terms you offer'
            )));

        $model = $this->buildModel($posted, $posted, $offered, ['fieldset_data' => $fieldsetData]);
        $model->beforeSave();
        $model->afterCommitCallback();

        $this->assertTrue(true, $case);
    }

    public static function announcementProvider(): array
    {
        return [
            ['30', [14, 30], ['payment_terms' => ['14']], true, 'a fold-in that landed is announced'],
            ['37', [14, 30], ['payment_terms' => ['14']], false, 'an untouched value is not announced'],
            ['30', [], ['payment_terms' => ['14']], false, 'an unresolvable offered set folds nothing in'],
            ['30', [14, 30], [], false, 'no fold-in happened, so there is nothing to announce'],
        ];
    }

    /**
     * The message queue is session-backed, so a notice emitted before the transaction commits
     * would survive a later field's refusal and report a clearing that rolled back.
     */
    public function testNothingIsAnnouncedBeforeTheCommit(): void
    {
        $this->messageManager->expects($this->never())->method('addNoticeMessage');

        $this->buildModel('30', '30', [14, 30])->beforeSave();
    }

    public function testTheNoticeIsNotRepeatedOnASecondCommit(): void
    {
        $this->messageManager->expects($this->once())->method('addNoticeMessage');

        $model = $this->buildModel('30', '30', [14, 30]);
        $model->beforeSave();
        $model->afterCommitCallback();
        $model->afterCommitCallback();
    }

    public function testTheStoredValueAndTheOfferedSetAreReadAtTheScopeBeingSaved(): void
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->expects($this->once())
            ->method('getValue')
            ->with('payment/two_payment/payment_terms_duration_days', 'stores', 'de')
            ->willReturn('30');

        $settingsProvider = $this->createMock(SettingsProvider::class);
        $settingsProvider->expects($this->once())
            ->method('getAvailableTerms')
            ->with(5)
            ->willReturn([30]);

        $model = new PaymentTermsCustomDays(
            $this->getMockBuilder(Context::class)->disableOriginalConstructor()->getMock(),
            $this->getMockBuilder(Registry::class)->disableOriginalConstructor()->getMock(),
            $scopeConfig,
            $this->createMock(TypeListInterface::class),
            new OfferedTermsGuard($settingsProvider),
            $this->messageManager,
            null,
            null,
            [
                'value' => '30',
                'path' => 'payment/two_payment/payment_terms_duration_days',
                'scope' => 'stores',
                'scope_id' => 5,
                'scope_code' => 'de',
                'fieldset_data' => ['payment_terms' => ['14']],
            ]
        );

        $model->beforeSave();

        $this->assertSame('', $model->getValue(), 'the store-scope offered set drives the fold-in');
    }
}
