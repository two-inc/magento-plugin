<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Model\Config\Comment;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\RequestInterface;
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
 * cannot explain the field (Q46).
 */
class PaymentTermsCustomDaysTest extends TestCase
{
    private const EOM_COPY = 'past the end of the month';

    /** @param array<string, mixed> $storedRows keyed `<path>@<scope>:<id>`, no inheritance */
    private function comment(array $storedRows, array $params = []): string
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
            new EndOfMonth()
        );

        return $model->getCommentText('');
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
        $this->assertStringContainsString('Optional.', $text, $case);
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
