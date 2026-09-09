<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Config;

use Magento\Backend\Block\Template\Context as BlockContext;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ProductMetadataInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Tax\Model\Calculation as TaxCalculation;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\BrandRegistryInterface;
use Two\Gateway\Api\CurrencyRatesProviderInterface;
use Two\Gateway\Api\Log\RepositoryInterface as LogRepository;
use Two\Gateway\Block\Adminhtml\System\Config\Field\SurchargeGrid;
use Two\Gateway\Model\Config\FieldGate\StoredValue;
use Two\Gateway\Model\Config\Repository;
use Two\Gateway\Model\Config\StoredTerm;
use Two\Gateway\Model\Provenance;
use Two\Gateway\Service\Locale\AdminDecimalFormatter;
use Two\Gateway\Service\Merchant\SettingsProvider;

/**
 * Every reader of `payment_terms_duration_days` resolves the same stored row to the same term.
 * A cast reads '1e2' as 100 and parseInt reads it as 1, so a reader that skips StoredTerm offers
 * the buyer a term the admin refuses to save (ABN-522).
 *
 * The browser-side reader is pinned separately, in Test/Js/custom-days-visibility.test.js: it
 * takes the term from the data-two-term the renderer emits, which this suite's block test asserts.
 */
class CustomTermParityTest extends TestCase
{
    private const PATH = 'payment/two_payment/payment_terms_duration_days';

    private function scopeConfig(string $stored, string $ticked = ''): ScopeConfigInterface
    {
        $rows = [self::PATH => $stored, 'payment/two_payment/payment_terms' => $ticked];
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(
            static fn ($path) => $rows[$path] ?? null
        );

        return $scopeConfig;
    }

    private function brandRegistry(): BrandRegistryInterface
    {
        $brandRegistry = $this->createMock(BrandRegistryInterface::class);
        $brandRegistry->method('getCode')->willReturn('two_payment');
        $brandRegistry->method('getProductName')->willReturn('Two');

        return $brandRegistry;
    }

    private function repositoryTerm(string $stored): int
    {
        $repository = new Repository(
            $this->scopeConfig($stored),
            $this->createMock(EncryptorInterface::class),
            $this->createMock(UrlInterface::class),
            $this->createMock(ProductMetadataInterface::class),
            $this->getMockBuilder(TaxCalculation::class)->disableOriginalConstructor()->getMock(),
            $this->brandRegistry(),
            $this->createMock(SettingsProvider::class),
            $this->createMock(Provenance::class),
            $this->createMock(LogRepository::class)
        );

        return $repository->getPaymentTermsDurationDays();
    }

    /** @return int[] */
    private function surchargeGridTerms(string $stored, string $ticked): array
    {
        $block = new SurchargeGrid(
            $this->createMock(BlockContext::class),
            $this->scopeConfig($stored, $ticked),
            $this->createMock(StoreManagerInterface::class),
            $this->createMock(CurrencyRatesProviderInterface::class),
            $this->brandRegistry(),
            $this->createMock(SettingsProvider::class),
            $this->createMock(AdminDecimalFormatter::class),
            $this->createMock(ResourceConnection::class)
        );

        return $block->getActiveTerms();
    }

    /**
     * @dataProvider storedProvider
     */
    public function testEveryReaderResolvesTheSameTerm(string $stored, ?int $expected, string $case): void
    {
        $this->assertSame($expected, StoredTerm::days($stored), "$case — StoredTerm");
        $this->assertSame($expected ?? 0, $this->repositoryTerm($stored), "$case — config repository");
        $this->assertSame(
            $expected === null ? [] : [$expected],
            $this->surchargeGridTerms($stored, ''),
            "$case — surcharge grid"
        );
        $this->assertSame(
            !StoredTerm::isBlank($stored),
            (new StoredValue())->isConfigured($stored),
            "$case — admin visibility gate"
        );
    }

    public static function storedProvider(): array
    {
        return [
            ['30', 30, 'a plain term'],
            ['030', 30, 'leading zeros'],
            ['  30  ', 30, 'padding'],
            ['', null, 'nothing stored'],
            ['0', null, 'a zero'],
            ['1e2', null, 'exponent notation, which a cast reads as 100 and parseInt as 1'],
            ['30.0', null, 'a decimal, which a cast reads as 30'],
            ['-5', null, 'a negative'],
            ['abc', null, 'non-numeric junk'],
            ['30abc', null, 'a numeric prefix, which a cast reads as 30'],
        ];
    }

    public function testTheGridKeepsTickedTermsAlongsideAResolvedCustomTerm(): void
    {
        $this->assertSame([14, 30, 37], $this->surchargeGridTerms('37', '14,30'));
    }

    public function testAnUnusableCustomTermLeavesTheTickedTermsUntouched(): void
    {
        $this->assertSame([14, 30], $this->surchargeGridTerms('1e2', '14,30'));
    }
}
