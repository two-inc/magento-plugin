<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Model\Config;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ProductMetadataInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\UrlInterface;
use Magento\Tax\Model\Calculation as TaxCalculation;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\BrandRegistryInterface;
use Two\Gateway\Model\Config\Repository;
use Two\Gateway\Model\Provenance;

/**
 * TWO-25197: the `client_v` telemetry parameter carries the deployed
 * commit, so a support enquiry can be tied to exact running code rather
 * than a release line that may cover many builds.
 */
class RepositoryVersionStampTest extends TestCase
{
    /** @var ScopeConfigInterface|\PHPUnit\Framework\MockObject\MockObject */
    private $scopeConfig;

    protected function setUp(): void
    {
        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);
    }

    private function repository(?string $version, string $commit): Repository
    {
        $this->scopeConfig->method('getValue')->willReturn($version);
        $brand = $this->createMock(BrandRegistryInterface::class);
        $brand->method('getCode')->willReturn('two_payment');
        $provenance = $this->createMock(Provenance::class);
        $provenance->method('commitForModule')->willReturn($commit);

        return new Repository(
            $this->scopeConfig,
            $this->createMock(EncryptorInterface::class),
            $this->createMock(UrlInterface::class),
            $this->createMock(ProductMetadataInterface::class),
            $this->createMock(TaxCalculation::class),
            $brand,
            $this->createMock(\Two\Gateway\Service\Merchant\SettingsProvider::class),
            $provenance
        );
    }

    public function testCommitIsAppendedToClientVersion(): void
    {
        $url = $this->repository('2.0.1', '6f8534e')
            ->addVersionDataInURL('https://api.two.inc/v1/order');

        // `+` MUST arrive percent-encoded: a literal `+` in a query value
        // decodes to a space server-side. http_build_query handles this.
        $this->assertStringContainsString('client_v=2.0.1%2B6f8534e', $url);
        $this->assertStringNotContainsString('+', $url);

        parse_str((string)parse_url($url, PHP_URL_QUERY), $q);
        $this->assertSame('2.0.1+6f8534e', $q['client_v']);
        $this->assertSame('Magento', $q['client']);
    }

    public function testNoTrailingPlusWhenCommitCannotBeResolved(): void
    {
        $url = $this->repository('2.0.1', '')
            ->addVersionDataInURL('https://api.two.inc/v1/order');

        parse_str((string)parse_url($url, PHP_URL_QUERY), $q);
        $this->assertSame('2.0.1', $q['client_v']);
        $this->assertStringNotContainsString('%2B', $url);
        $this->assertStringNotContainsString('+', $url);
    }

    public function testAbsentVersionStaysAbsentEvenWithACommit(): void
    {
        // No configured version and a resolvable SHA must not produce a
        // bare `+6f8534e`.
        $url = $this->repository(null, '6f8534e')
            ->addVersionDataInURL('https://api.two.inc/v1/order');

        $this->assertStringNotContainsString('client_v', $url);
        $this->assertStringContainsString('client=Magento', $url);
    }

    public function testDbVersionStaysUnstamped(): void
    {
        // getExtensionDBVersion() is the schema/release version callers
        // compare against release numbers — no provenance suffix.
        $this->assertSame(
            '2.0.1',
            $this->repository('2.0.1', '6f8534e')->getExtensionDBVersion()
        );
    }
}
