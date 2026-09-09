<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Config;

use Magento\Backend\Block\Template\Context as BlockContext;
use Magento\Config\Model\Config\Loader;
use Magento\Config\Model\Config\Reader\Source\Deployed\SettingChecker;
use Magento\Config\Model\Config\Structure;
use Magento\Config\Model\Config\Structure\Element\Field as StructureField;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Framework\Message\ManagerInterface as MessageManager;
use Magento\Framework\Model\Context as ModelContext;
use Magento\Framework\Registry;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Block\Adminhtml\System\Config\Field\PaymentTermsCustomDays as CustomDaysField;
use Two\Gateway\Model\Config\Backend\PaymentTerms\OfferedTermsGuard;
use Two\Gateway\Model\Config\Backend\PaymentTermsCustomDays as CustomDaysBackend;
use Two\Gateway\Service\Merchant\SettingsProvider;

/**
 * The rendered fold-in marker hides the deprecated custom-term row, so it may not claim a fold-in
 * the save will not perform — a merchant left with a hidden row and a kept value has no control to
 * remove it (ABN-522).
 */
class PaymentTermsFoldInAgreementTest extends TestCase
{
    private const STRUCTURE_PATH = 'two_payment/payment_terms';

    /** Public: the anonymous Structure and Field subclasses below read them. */
    public const SIBLING_STRUCTURE_PATH = 'two_payment/payment_terms/payment_terms';

    public const SIBLING_CONFIG_PATH = 'payment/two_payment/payment_terms';

    /** Public: the anonymous Loader subclass below reads it. */
    public const OWN_CONFIG_PATH = 'payment/two_payment/payment_terms_duration_days';

    /** @param int[] $offered */
    private function markerIsRendered(string $stored, array $offered, bool $envLocked): bool
    {
        $context = $this->createMock(BlockContext::class);
        $context->method('getRequest')->willReturn($this->createMock(RequestInterface::class));

        $block = new class (
            $context,
            new OfferedTermsGuard($this->settingsProvider($offered)),
            $this->createMock(StoreManagerInterface::class),
            $this->settingChecker($envLocked),
            self::structure()
        ) extends CustomDaysField {
            public function renderForTest(AbstractElement $element): string
            {
                return $this->_getElementHtml($element);
            }
        };

        $html = $block->renderForTest(new AbstractElement([
            'value' => $stored,
            'html_id' => 'two_payment_payment_terms_payment_terms_duration_days',
            'name' => 'groups[payment_terms][fields][payment_terms_duration_days][value]',
            'field_config' => ['path' => self::STRUCTURE_PATH],
        ]));

        return strpos($html, 'two-legacy-term-folds-in') !== false;
    }

    /** @param int[] $offered */
    private function saveFoldsTheValueAway(
        string $stored,
        array $offered,
        bool $envLocked,
        bool $siblingInherits
    ): bool {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturn($stored);

        $model = new CustomDaysBackend(
            $this->getMockBuilder(ModelContext::class)->disableOriginalConstructor()->getMock(),
            $this->getMockBuilder(Registry::class)->disableOriginalConstructor()->getMock(),
            $scopeConfig,
            $this->createMock(TypeListInterface::class),
            new OfferedTermsGuard($this->settingsProvider($offered)),
            $this->createMock(MessageManager::class),
            $this->settingChecker($envLocked),
            self::structure(),
            self::configLoader($stored),
            null,
            null,
            [
                'value' => $stored,
                'path' => 'payment/two_payment/payment_terms_duration_days',
                'scope' => 'default',
                'scope_id' => 0,
                'group_id' => 'payment_terms',
                'field_config' => ['path' => self::STRUCTURE_PATH],
                'groups' => ['payment_terms' => ['fields' => [
                    'payment_terms_duration_days' => ['value' => $stored],
                    'payment_terms' => $siblingInherits ? ['inherit' => '1'] : ['value' => ''],
                ]]],
            ]
        );
        $model->beforeSave();

        return $stored !== '' && (string)$model->getValue() === '';
    }

    /** @param int[] $offered */
    private function settingsProvider(array $offered): SettingsProvider
    {
        $settingsProvider = $this->createMock(SettingsProvider::class);
        $settingsProvider->method('getAvailableTerms')->willReturn($offered);

        return $settingsProvider;
    }

    private function settingChecker(bool $envLocked): SettingChecker
    {
        $settingChecker = $this->createMock(SettingChecker::class);
        $settingChecker->method('isReadOnly')->willReturnCallback(
            static fn ($path) => $envLocked && $path === self::SIBLING_CONFIG_PATH
        );

        return $settingChecker;
    }

    /** The row the form rendered from, keyed by path as Magento\Config\Model\Config\Loader keys it. */
    private static function configLoader(string $row): Loader
    {
        return new class ($row) extends Loader {
            // phpcs:disable
            public function __construct(private string $row)
            {
            }
            public function getConfigByPath($path, $scope, $scopeId, $full = true)
            {
                return [PaymentTermsFoldInAgreementTest::OWN_CONFIG_PATH => $this->row];
            }
            // phpcs:enable
        };
    }

    /** Declares the sibling's config path, as etc/adminhtml/system.xml does. */
    private static function structure(): Structure
    {
        return new class extends Structure {
            // phpcs:disable
            public function getElement($path)
            {
                $configPath = $path === PaymentTermsFoldInAgreementTest::SIBLING_STRUCTURE_PATH
                    ? PaymentTermsFoldInAgreementTest::SIBLING_CONFIG_PATH
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
     * @param int[] $offered
     * @dataProvider agreementProvider
     */
    public function testTheMarkerClaimsAFoldInOnlyWhereTheSaveFoldsOne(
        string $stored,
        array $offered,
        bool $envLocked,
        bool $siblingInherits,
        bool $expectedMarker,
        bool $expectedFold,
        string $case
    ): void {
        $this->assertSame(
            [$expectedMarker, $expectedFold],
            [
                $this->markerIsRendered($stored, $offered, $envLocked),
                $this->saveFoldsTheValueAway($stored, $offered, $envLocked, $siblingInherits),
            ],
            $case
        );
    }

    public static function agreementProvider(): array
    {
        return [
            ['30', [14, 30], false, false, true, true, 'an editable sibling and an offered term: the row hides and the save folds'],
            ['30', [14, 30], true, false, false, false, 'an env.php-locked sibling never takes the tick, so nothing hides and nothing folds'],
            ['37', [14, 30], false, false, false, false, 'a term the merchant record does not offer'],
            ['30', [], false, false, false, false, 'an unresolvable offered set matches nothing'],
            [
                '30',
                [14, 30],
                false,
                true,
                true,
                false,
                'an inheriting sibling is settled in the browser, not here: the marker stands and the save keeps'
                . ' the value, and Test/Js/custom-days-visibility.test.js pins the row staying visible',
            ],
        ];
    }
}
