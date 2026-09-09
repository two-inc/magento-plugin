<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Model\Config\Comment;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Escaper;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Api\Data\WebsiteInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\BrandRegistryInterface;
use Two\Gateway\Model\Config\Comment\PaymentTermsCustomDays;
use Two\Gateway\Model\Config\FieldGate\EndOfMonth;

/**
 * The help text under "Custom payment terms (days)". End-of-Month semantics
 * are named only where End of Month is stored at the scope being edited — the
 * selector carrying that choice is hidden under Standard, so its wording
 * cannot explain the field (ABN-495).
 */
class PaymentTermsCustomDaysTest extends TestCase
{
    private const EOM_COPY = 'after the end of the month';

    /** @param array<string, mixed> $storedRows keyed `<path>@<scope>:<id>`, no inheritance */
    private function comment(array $storedRows, array $params = [], string $value = ''): string
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(
            static fn ($path, $scopeType = 'default', $scopeCode = null) => $storedRows["$path@$scopeType:$scopeCode"] ?? null
        );

        $request = $this->createMock(RequestInterface::class);
        $request->method('getParam')->willReturnCallback(static fn ($key) => $params[$key] ?? null);

        $store = $this->createMock(StoreInterface::class);
        $store->method('getId')->willReturn(2);
        $website = $this->createMock(WebsiteInterface::class);
        $website->method('getId')->willReturn(3);
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturnCallback(
            static fn ($code) => $code === 'broken' ? throw new \RuntimeException('no such store') : $store
        );
        $storeManager->method('getWebsite')->willReturn($website);

        $brandRegistry = $this->createMock(BrandRegistryInterface::class);
        $brandRegistry->method('getCode')->willReturn('two_payment');

        $model = new PaymentTermsCustomDays(
            $scopeConfig,
            $request,
            $storeManager,
            $brandRegistry,
            new EndOfMonth(),
            new Escaper()
        );

        return $model->getCommentText($value);
    }

    /**
     * @param array<string, mixed> $storedRows
     * @param array<string, string> $params
     * @dataProvider storedTypeProvider
     */
    public function testEndOfMonthWordingFollowsTheStoredType(
        array $storedRows,
        array $params,
        bool $expectEom,
        string $case
    ): void {
        $text = $this->comment($storedRows, $params);

        $this->assertSame($expectEom, str_contains($text, self::EOM_COPY), $case);
        $this->assertStringContainsString('Legacy setting.', $text, $case);
    }

    /**
     * @param array<string, mixed> $storedRows
     * @dataProvider interpolatedDaysProvider
     */
    public function testTheHintNamesTheStoredTerm(
        array $storedRows,
        string $value,
        string $expected,
        string $case
    ): void {
        $text = $this->comment($storedRows, [], $value);

        $this->assertStringContainsString($expected, $text, $case);
        $this->assertStringNotContainsString('%1', $text, "$case — the placeholder is filled");
    }

    /** The hint is the only place the merchant is told why the section will not save. */
    public function testTheUnusableWordingNamesTheBlockAndTheRemedy(): void
    {
        $text = $this->comment([], [], 'abc');

        $this->assertStringContainsString('this section cannot be saved until it is removed', $text);
        $this->assertStringContainsString('Choose Remove to clear it.', $text);
        $this->assertStringNotContainsString('offers a custom term', $text);
    }

    /**
     * Comment output is rendered raw, so a stored value the admin form never validated reaches
     * the page as markup unless it is escaped on the way in.
     */
    public function testAnUnusableStoredValueCannotInjectMarkup(): void
    {
        $text = $this->comment([], [], '<img src=x onerror=alert(1)>');

        $this->assertStringNotContainsString('<img', $text);
        $this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $text);
    }

    public static function interpolatedDaysProvider(): array
    {
        $eom = ['payment/two_payment/payment_terms_type@default:' => 'end_of_month'];

        return [
            [$eom, '37', 'custom term of 37 days after the end of the month', 'End of Month names the term'],
            [[], '37', 'custom term of 37 days from fulfilment', 'Standard names the term'],
            [$eom, '037', 'custom term of 37 days', 'a leading-zero value names the normalised term'],
            [[], '  37  ', 'custom term of 37 days', 'padding is trimmed out of the wording'],
            [
                [],
                'abc',
                'Legacy setting currently holds "abc", which is not a usable number of days.',
                'an unusable value says nothing is offered and names the value as stored',
            ],
            [
                [],
                '-5',
                'Legacy setting currently holds "-5", which is not a usable number of days.',
                'a negative is unusable and is named as stored',
            ],
            [
                $eom,
                'abc',
                'Legacy setting currently holds "abc", which is not a usable number of days.',
                'the unusable wording does not depend on the terms type',
            ],
        ];
    }

    public static function storedTypeProvider(): array
    {
        $path = 'payment/two_payment/payment_terms_type';

        return [
            [["$path@default:" => 'end_of_month'], [], true, 'End of Month at default scope'],
            [["$path@default:" => 'standard'], [], false, 'Standard at default scope'],
            [[], [], false, 'nothing stored reads as Standard'],
            [["$path@store:2" => 'end_of_month'], ['store' => 'default'], true, 'the store being edited'],
            [
                ["$path@default:" => 'end_of_month'],
                ['store' => 'default'],
                false,
                'a store scope reads its own value, not the default scope',
            ],
            [["$path@website:3" => 'end_of_month'], ['website' => 'base'], true, 'the website being edited'],
            [
                ["$path@default:" => 'end_of_month'],
                ['store' => 'broken'],
                false,
                'an unresolvable scope param falls back to the Standard wording',
            ],
        ];
    }
}
