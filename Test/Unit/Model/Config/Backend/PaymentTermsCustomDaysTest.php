<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Model\Config\Backend;

use Magento\Config\Model\Config\Loader;
use Magento\Config\Model\Config\Reader\Source\Deployed\SettingChecker;
use Magento\Config\Model\Config\Structure;
use Magento\Config\Model\Config\Structure\Element\Field as StructureField;
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
    /** Public: the anonymous Structure and Field subclasses below read them. */
    public const SIBLING_STRUCTURE_PATH = 'two_payment/payment_terms/payment_terms';

    public const SIBLING_CONFIG_PATH = 'payment/two_payment/payment_terms';

    /** Public: the anonymous Loader subclass below reads them. */
    public const OWN_PATH = 'payment/two_payment/payment_terms_duration_days';

    public const GROUP_PATH = 'payment/two_payment';
    /** @var MessageManager|MockObject */
    private $messageManager;

    /** @var SettingChecker|MockObject */
    private $settingChecker;

    protected function setUp(): void
    {
        $this->messageManager = $this->createMock(MessageManager::class);
        $this->settingChecker = $this->createMock(SettingChecker::class);
    }

    /**
     * @param int[] $offered terms the merchant record offers; empty means it did not resolve
     * @param array<string, mixed> $data extra model data, e.g. the scope being saved
     * @param array|null $sibling posted shape of the checkboxes field; null means absent from the post
     * @param string|null $row config table row at this scope; null means the scope holds none
     */
    private function buildModel(
        string $posted,
        ?string $stored,
        array $offered = [],
        array $data = [],
        ?array $sibling = ['value' => ''],
        ?string $row = null
    ): PaymentTermsCustomDays {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturn($stored);

        $settingsProvider = $this->createMock(SettingsProvider::class);
        $settingsProvider->method('getAvailableTerms')->willReturn($offered);

        $fields = ['payment_terms_duration_days' => ['value' => $posted]];
        if ($sibling !== null) {
            $fields['payment_terms'] = $sibling;
        }

        return new PaymentTermsCustomDays(
            $this->getMockBuilder(Context::class)->disableOriginalConstructor()->getMock(),
            $this->getMockBuilder(Registry::class)->disableOriginalConstructor()->getMock(),
            $scopeConfig,
            $this->createMock(TypeListInterface::class),
            new OfferedTermsGuard($settingsProvider),
            $this->messageManager,
            $this->settingChecker,
            self::structure(),
            self::configLoader($row),
            null,
            null,
            $data + [
                'value' => $posted,
                'path' => 'payment/two_payment/payment_terms_duration_days',
                'scope' => 'default',
                'scope_id' => 0,
                'group_id' => 'payment_terms',
                'field_config' => ['path' => 'two_payment/payment_terms'],
                'groups' => ['payment_terms' => ['fields' => $fields]],
            ]
        );
    }

    /** Declares the sibling's config path, as etc/adminhtml/system.xml does. */
    private static function structure(): Structure
    {
        return new class extends Structure {
            // phpcs:disable
            public function getElement($path)
            {
                $configPath = $path === PaymentTermsCustomDaysTest::SIBLING_STRUCTURE_PATH
                    ? PaymentTermsCustomDaysTest::SIBLING_CONFIG_PATH
                    : null;

                return new class ($configPath) extends StructureField {
                    public function __construct(private ?string $configPath)
                    {
                    }
                    public function getConfigPath()
                    {
                        return $this->configPath;
                    }
                };
            }
            // phpcs:enable
        };
    }

    /**
     * Rows at the scope queried, keyed by path as Magento\Config\Model\Config\Loader keys them.
     * Any other scope answers with a value nothing expects, so a mis-scoped read shows up.
     */
    private static function configLoader(?string $row, string $scope = 'default', int $scopeId = 0): Loader
    {
        return new class ($row, [$scope, $scopeId]) extends Loader {
            // phpcs:disable
            public function __construct(private ?string $row, private array $scope)
            {
            }
            public function getConfigByPath($path, $scope, $scopeId, $full = true)
            {
                if ($this->row === null) {
                    return [];
                }
                $queried = [$path, $scope, $scopeId]
                    === [PaymentTermsCustomDaysTest::GROUP_PATH, $this->scope[0], $this->scope[1]];

                return [PaymentTermsCustomDaysTest::OWN_PATH => $queried ? $this->row : 'wrong-scope'];
            }
            // phpcs:enable
        };
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
     * The matching tick is the sibling field's write, and Magento skips that write for a field
     * left inheriting or locked in env.php. The posted value cannot report either: the checkboxes
     * template always emits an empty hidden fallback, so an inheriting sibling posts '' as well.
     *
     * @param array|null $sibling
     * @dataProvider siblingWriteProvider
     */
    public function testTheFoldInNeedsTheSiblingWriteInTheSameSave(
        ?array $sibling,
        bool $readOnly,
        string $expected,
        string $case
    ): void {
        $this->settingChecker->method('isReadOnly')->willReturn($readOnly);
        $model = $this->buildModel('30', '30', [14, 30], [], $sibling);

        $model->beforeSave();

        $this->assertSame($expected, $model->getValue(), $case);
    }

    public static function siblingWriteProvider(): array
    {
        return [
            [['value' => ['14']], false, '', 'a posted selection takes the term, so this field clears'],
            [['value' => ''], false, '', 'the template hidden fallback alone is still a write'],
            [['value' => '', 'inherit' => '1'], false, '30', 'a sibling left inheriting is deleted, not written'],
            [['value' => ['14'], 'inherit' => '1'], false, '30', 'the inherit flag decides even with a value posted'],
            [['value' => ['14']], true, '30', 'a sibling locked in env.php is skipped before its model runs'],
            [null, false, '30', 'a sibling absent from the post writes nothing'],
        ];
    }

    /** Env.php locks are keyed by config path, so the sibling's structure path matches none. */
    public function testTheReadOnlyCheckAsksAboutTheSiblingAtTheScopeBeingSaved(): void
    {
        $this->settingChecker->expects($this->once())
            ->method('isReadOnly')
            ->with(self::SIBLING_CONFIG_PATH, 'stores', 'de')
            ->willReturn(false);

        $model = $this->buildModel(
            '30',
            '30',
            [14, 30],
            ['scope' => 'stores', 'scope_id' => 5, 'scope_code' => 'de']
        );

        $model->beforeSave();

        $this->assertSame('', $model->getValue());
    }

    /**
     * @param int[] $offered
     * @param array|null $sibling
     * @dataProvider announcementProvider
     */
    public function testTheFoldInIsAnnouncedOnlyOnceTheSaveCommits(
        string $posted,
        array $offered,
        ?array $sibling,
        bool $expectNotice,
        string $case
    ): void {
        $this->messageManager->expects($expectNotice ? $this->once() : $this->never())
            ->method('addNoticeMessage')
            ->with($this->callback(static fn ($message): bool => str_contains(
                (string)$message,
                'Custom payment terms (days) of 30 is now one of the standard terms you offer'
            )));

        $model = $this->buildModel($posted, $posted, $offered, [], $sibling);
        $model->beforeSave();
        $model->afterCommitCallback();

        $this->assertTrue(true, $case);
    }

    public static function announcementProvider(): array
    {
        return [
            ['30', [14, 30], ['value' => ['14']], true, 'a fold-in that landed is announced'],
            ['37', [14, 30], ['value' => ['14']], false, 'an untouched value is not announced'],
            ['30', [], ['value' => ['14']], false, 'an unresolvable offered set folds nothing in'],
            [
                '30',
                [14, 30],
                ['value' => '', 'inherit' => '1'],
                false,
                'an inheriting sibling folds nothing in, so there is nothing to announce',
            ],
        ];
    }

    /**
     * The renderer offers the config table row as the keep option, so the guard has to judge the
     * post against that row rather than getOldValue()'s cached resolution of the same path
     * (ABN-531).
     *
     * @param int[] $offered
     * @dataProvider rowVersusCacheProvider
     */
    public function testThePostIsJudgedAgainstTheRowTheFormRendered(
        string $posted,
        ?string $row,
        ?string $cached,
        array $offered,
        ?string $expected,
        string $case
    ): void {
        $model = $this->buildModel($posted, $cached, $offered, [], ['value' => ['14']], $row);

        if ($expected === null) {
            $this->expectException(LocalizedException::class);
            $this->expectExceptionMessage('Custom payment terms (days) can only be removed, not changed.');
        }

        $model->beforeSave();

        $this->assertSame($expected, $model->getValue(), $case);
    }

    public static function rowVersusCacheProvider(): array
    {
        return [
            ['45', '45', '30', [], '45', 'a stale cache no longer blocks a save that keeps the row'],
            ['  45  ', '45', '30', [], '45', 'whitespace around the kept row is not a change'],
            ['', '45', '30', [], '', 'removal still clears the term while the two readings differ'],
            ['30', '45', '30', [], null, 'the cached reading is not on offer, so posting it is a change'],
            ['45', null, '45', [], '45', 'no row at this scope falls back to the resolved reading'],
            ['45', null, '30', [], null, 'no row and a value matching neither reading is refused'],
            ['30', '30', null, [14, 30], '', 'the row drives the fold-in with nothing cached'],
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
            $this->settingChecker,
            self::structure(),
            self::configLoader(null),
            null,
            null,
            [
                'value' => '30',
                'path' => 'payment/two_payment/payment_terms_duration_days',
                'scope' => 'stores',
                'scope_id' => 5,
                'scope_code' => 'de',
                'group_id' => 'payment_terms',
                'field_config' => ['path' => 'two_payment/payment_terms'],
                'groups' => ['payment_terms' => ['fields' => ['payment_terms' => ['value' => ['14']]]]],
            ]
        );

        $model->beforeSave();

        $this->assertSame('', $model->getValue(), 'the store-scope offered set drives the fold-in');
    }

    /** The row is read at the scope being saved; a wider scope's row is not what the page showed. */
    public function testTheRowIsReadAtTheScopeBeingSaved(): void
    {
        $model = new PaymentTermsCustomDays(
            $this->getMockBuilder(Context::class)->disableOriginalConstructor()->getMock(),
            $this->getMockBuilder(Registry::class)->disableOriginalConstructor()->getMock(),
            $this->createMock(ScopeConfigInterface::class),
            $this->createMock(TypeListInterface::class),
            new OfferedTermsGuard($this->createMock(SettingsProvider::class)),
            $this->messageManager,
            $this->settingChecker,
            self::structure(),
            self::configLoader('45', 'stores', 5),
            null,
            null,
            [
                'value' => '45',
                'path' => self::OWN_PATH,
                'scope' => 'stores',
                'scope_id' => 5,
                'scope_code' => 'de',
                'group_id' => 'payment_terms',
                'field_config' => ['path' => 'two_payment/payment_terms'],
                'groups' => ['payment_terms' => ['fields' => ['payment_terms' => ['value' => ['14']]]]],
            ]
        );

        $model->beforeSave();

        $this->assertSame('45', $model->getValue());
    }
}
