<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Block\Adminhtml\System\Config\Field;

use DOMDocument;
use DOMElement;
use Magento\Backend\Block\Template\Context;
use Magento\Config\Model\Config\Reader\Source\Deployed\SettingChecker;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Api\Data\WebsiteInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Block\Adminhtml\System\Config\Field\PaymentTermsCustomDays;
use Two\Gateway\Model\Config\Backend\PaymentTerms\OfferedTermsGuard;
use Two\Gateway\Service\Merchant\SettingsProvider;

/**
 * The deprecated custom term is offered as keep-or-remove, never as free entry, and each option
 * carries the server's own normalisation as data-two-term (ABN-522).
 *
 * Attributes are read back through a parser, not matched as literals: the real escapeHtmlAttr
 * emits numeric entities for brackets.
 */
class PaymentTermsCustomDaysTest extends TestCase
{
    private const STRUCTURE_PATH = 'two_payment/payment_terms';

    /**
     * @param int[] $offered
     * @param array<string, string> $params the admin page's own request params
     */
    private function block(
        array $offered = [],
        array $params = [],
        ?SettingsProvider $settingsProvider = null,
        bool $siblingEnvLocked = false
    ): PaymentTermsCustomDays {
        if ($settingsProvider === null) {
            $settingsProvider = $this->createMock(SettingsProvider::class);
            $settingsProvider->method('getAvailableTerms')->willReturn($offered);
        }

        $request = $this->createMock(RequestInterface::class);
        $request->method('getParam')->willReturnCallback(static fn ($key) => $params[$key] ?? null);
        $context = $this->createMock(Context::class);
        $context->method('getRequest')->willReturn($request);

        $store = $this->createMock(StoreInterface::class);
        $store->method('getId')->willReturn(5);
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturnCallback(
            static fn ($code) => $code === 'broken' ? throw new \RuntimeException('no such store') : $store
        );
        $storeManager->method('getWebsite')->willReturn($this->createMock(WebsiteInterface::class));

        $settingChecker = $this->createMock(SettingChecker::class);
        $settingChecker->method('isReadOnly')->willReturn($siblingEnvLocked);

        return new class ($context, new OfferedTermsGuard($settingsProvider), $storeManager, $settingChecker)
            extends PaymentTermsCustomDays {
            public function renderForTest(AbstractElement $element): string
            {
                return $this->_getElementHtml($element);
            }
        };
    }

    /** @param int[] $offered */
    private function render(
        array $elementData,
        array $offered = [],
        array $params = [],
        bool $siblingEnvLocked = false
    ): string {
        return $this->block($offered, $params, null, $siblingEnvLocked)
            ->renderForTest(new AbstractElement($elementData + [
                'html_id' => 'two_payment_payment_terms_payment_terms_duration_days',
                'name' => 'groups[payment_terms][fields][payment_terms_duration_days][value]',
                'field_config' => ['path' => self::STRUCTURE_PATH],
            ]));
    }

    private function parse(string $html): DOMDocument
    {
        $document = new DOMDocument();
        $this->assertTrue(
            $document->loadHTML('<html><body>' . $html . '</body></html>', LIBXML_NOERROR),
            'the renderer must emit parseable markup'
        );

        return $document;
    }

    private function select(string $html): DOMElement
    {
        $selects = $this->parse($html)->getElementsByTagName('select');
        $this->assertCount(1, $selects, 'exactly one control is rendered');

        return $selects->item(0);
    }

    /** @return array<int, array{value: string, term: string, label: string, selected: bool}> */
    private function options(string $html): array
    {
        $options = [];
        foreach ($this->select($html)->getElementsByTagName('option') as $option) {
            $options[] = [
                'value' => $option->getAttribute('value'),
                'term' => $option->getAttribute('data-two-term'),
                'label' => $option->textContent,
                'selected' => $option->hasAttribute('selected'),
            ];
        }

        return $options;
    }

    /**
     * @param array<int, array{value: string, term: string, label: string, selected: bool}> $expected
     * @dataProvider optionsProvider
     */
    public function testTheOptionsOffered(string $stored, array $expected, string $case): void
    {
        $this->assertSame($expected, $this->options($this->render(['value' => $stored])), $case);
    }

    public static function optionsProvider(): array
    {
        $remove = ['value' => '', 'term' => '0', 'label' => 'Remove', 'selected' => false];

        return [
            [
                '37',
                [['value' => '37', 'term' => '37', 'label' => '37 days', 'selected' => true], $remove],
                'the stored term is the selection, and removal the only alternative',
            ],
            [
                '037',
                [['value' => '037', 'term' => '37', 'label' => '37 days', 'selected' => true], $remove],
                'the option keeps the stored value verbatim but carries the normalised term',
            ],
            [
                ' 37 ',
                [['value' => '37', 'term' => '37', 'label' => '37 days', 'selected' => true], $remove],
                'padding is trimmed out of both',
            ],
            [
                '1e2',
                [['value' => '1e2', 'term' => '0', 'label' => '1e2', 'selected' => true], $remove],
                'an unusable value contributes no term, where a cast or parseInt would invent one',
            ],
            [
                'abc',
                [['value' => 'abc', 'term' => '0', 'label' => 'abc', 'selected' => true], $remove],
                'junk is shown verbatim so it can be recognised and removed',
            ],
            [
                '',
                [['value' => '', 'term' => '0', 'label' => 'Remove', 'selected' => true]],
                'nothing stored leaves only removal',
            ],
        ];
    }

    /**
     * @dataProvider attributeProvider
     */
    public function testTheControlKeepsTheFieldsOwnIdentity(string $attribute, string $expected): void
    {
        $this->assertSame($expected, $this->select($this->render(['value' => '37']))->getAttribute($attribute));
    }

    public static function attributeProvider(): array
    {
        return [
            'id the admin scripts read the term from' => [
                'id',
                'two_payment_payment_terms_payment_terms_duration_days',
            ],
            'name the section save posts under' => [
                'name',
                'groups[payment_terms][fields][payment_terms_duration_days][value]',
            ],
        ];
    }

    /**
     * @param int[] $offered
     * @dataProvider foldsInProvider
     */
    public function testTheFoldInMarker(
        string $stored,
        array $offered,
        bool $siblingEnvLocked,
        bool $expected,
        string $case
    ): void {
        $markers = $this->parse($this->render(['value' => $stored], $offered, [], $siblingEnvLocked))
            ->getElementsByTagName('span');

        $this->assertSame($expected, $markers->length === 1, $case);
    }

    public static function foldsInProvider(): array
    {
        return [
            ['30', [14, 30], false, true, 'a term the record offers folds in, so the row hides'],
            ['030', [14, 30], false, true, 'a leading-zero value folds into the same term'],
            ['37', [14, 30], false, false, 'a term the record does not offer keeps the row visible'],
            ['30', [], false, false, 'an unresolvable offered set folds nothing in'],
            ['abc', [14, 30], false, false, 'an unusable value has no term to fold into'],
            ['1e2', [100], false, false, 'an unusable value is not the term a cast would read it as'],
            ['30', [14, 30], true, false, 'an env.php-locked sibling cannot take the tick, so the row stays visible'],
        ];
    }

    /** The backend model refuses on a missing structure path too, so the marker must not claim one. */
    public function testAnUnknownStructurePathClaimsNoFoldIn(): void
    {
        $html = $this->block([30])->renderForTest(new AbstractElement([
            'value' => '30',
            'html_id' => 'two_payment_payment_terms_payment_terms_duration_days',
            'name' => 'groups[payment_terms][fields][payment_terms_duration_days][value]',
        ]));

        $this->assertSame(0, $this->parse($html)->getElementsByTagName('span')->length);
    }

    public function testNoFreeTextEntryIsOffered(): void
    {
        $this->assertCount(
            0,
            $this->parse($this->render(['value' => '37']))->getElementsByTagName('input'),
            'a text input would invite an edit the save refuses'
        );
    }

    public function testAStoredValueCannotInjectMarkup(): void
    {
        $html = $this->render(['value' => '"><script>x</script>']);

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertSame('"><script>x</script>', $this->options($html)[0]['value']);
    }

    /**
     * @dataProvider disabledProvider
     */
    public function testAnInheritedScopeIsNotEditable(array $elementData, bool $expected, string $case): void
    {
        $this->assertSame(
            $expected,
            $this->select($this->render($elementData))->hasAttribute('disabled'),
            $case
        );
    }

    public static function disabledProvider(): array
    {
        return [
            [['value' => '37'], false, 'an editable scope posts the value back'],
            [['value' => '37', 'disabled' => true], true, 'an inherited scope is not editable'],
        ];
    }

    /**
     * @param array<string, string> $params
     * @dataProvider scopeProvider
     */
    public function testTheOfferedSetIsResolvedForTheScopeBeingEdited(
        array $params,
        ?int $expectedStoreId,
        string $case
    ): void {
        $settingsProvider = $this->createMock(SettingsProvider::class);
        $settingsProvider->expects($this->once())
            ->method('getAvailableTerms')
            ->with($expectedStoreId)
            ->willReturn([30]);

        $html = $this->block([], $params, $settingsProvider)->renderForTest(new AbstractElement([
            'value' => '30',
            'html_id' => 'two_payment_payment_terms_payment_terms_duration_days',
            'name' => 'groups[payment_terms][fields][payment_terms_duration_days][value]',
            'field_config' => ['path' => self::STRUCTURE_PATH],
        ]));

        $this->assertSame(1, $this->parse($html)->getElementsByTagName('span')->length, $case);
    }

    public static function scopeProvider(): array
    {
        return [
            [['store' => 'de'], 5, 'the store param names the store whose record is asked'],
            [[], null, 'no param is the default scope'],
            [['website' => 'eu'], null, 'a website scope has no single store to ask for'],
            [['store' => ''], null, 'an empty param is not a scope'],
            [['store' => 'broken'], null, 'an unresolvable store falls back rather than throwing'],
        ];
    }

    /**
     * The form object never carries scope, so reading it resolved every scope to default and the
     * marker was computed from the default record's offered set at store scope.
     */
    public function testTheFormObjectIsNotTheScopeSource(): void
    {
        $settingsProvider = $this->createMock(SettingsProvider::class);
        $settingsProvider->expects($this->once())->method('getAvailableTerms')->with(5)->willReturn([30]);

        $form = new class {
            public function getScope(): string
            {
                return 'default';
            }

            public function getScopeId(): int
            {
                return 0;
            }
        };

        $html = $this->block([], ['store' => 'de'], $settingsProvider)->renderForTest(new AbstractElement([
            'value' => '30',
            'html_id' => 'two_payment_payment_terms_payment_terms_duration_days',
            'name' => 'groups[payment_terms][fields][payment_terms_duration_days][value]',
            'field_config' => ['path' => self::STRUCTURE_PATH],
            'form' => $form,
        ]));

        $this->assertSame(1, $this->parse($html)->getElementsByTagName('span')->length);
    }
}
