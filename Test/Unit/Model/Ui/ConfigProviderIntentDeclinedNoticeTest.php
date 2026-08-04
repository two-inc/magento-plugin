<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Model\Ui;

use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\BrandRegistryInterface;
use Two\Gateway\Model\Ui\ConfigProvider;

/**
 * ConfigProvider's intent-DECLINED-notice payload resolution (TWO-25326
 * §7.3/§7.4, 2026-08-03 ruling). Mirrors
 * ConfigProviderIntentApprovedNoticeTest — same suppression switch
 * (isIntentApprovedNoticeEnabled), a SEPARATE copy override
 * (getIntentDeclinedNotice), and a company-number substitution the
 * approved notice also gained in the same ruling.
 */
class ConfigProviderIntentDeclinedNoticeTest extends TestCase
{
    public function testReturnsNullWhenTheBrandDisabledTheNotice(): void
    {
        $payload = $this->resolveFor(false, null);

        $this->assertNull($payload);
    }

    public function testReturnsNullWhenDisabledEvenWithACopyOverride(): void
    {
        $payload = $this->resolveFor(false, 'Custom %1 decline for %2 (%3).');

        $this->assertNull($payload);
    }

    public function testReturnsDefaultCopyWhenEnabledWithNoOverride(): void
    {
        $payload = $this->resolveFor(true, null);

        $this->assertIsArray($payload);
        $this->assertSame(
            'Acme is not available for this order by '
            . ConfigProvider::COMPANY_NAME_TOKEN
            . ' (' . ConfigProvider::COMPANY_NUMBER_TOKEN . ')',
            $payload['withCompany']
        );
        $this->assertSame(
            'Acme is not available for this order',
            $payload['withoutCompany']
        );
        $this->assertSame(ConfigProvider::COMPANY_NAME_TOKEN, $payload['companyNameToken']);
        $this->assertSame(ConfigProvider::COMPANY_NUMBER_TOKEN, $payload['companyNumberToken']);
    }

    public function testOverrideReplacesTheCompanyKnownVariantOnly(): void
    {
        $payload = $this->resolveFor(true, 'Custom %1 decline for %2 (%3).');

        $this->assertIsArray($payload);
        $this->assertSame(
            'Custom Acme decline for '
            . ConfigProvider::COMPANY_NAME_TOKEN
            . ' (' . ConfigProvider::COMPANY_NUMBER_TOKEN . ').',
            $payload['withCompany']
        );
        $this->assertSame(
            'Acme is not available for this order',
            $payload['withoutCompany']
        );
    }

    public function testApprovedOverrideDoesNotLeakIntoTheDeclinedCopy(): void
    {
        // §7.4: a brand's approved wording must never be forced onto the
        // declined variant, or vice versa — the two overrides are
        // independent knobs.
        $registry = $this->createMock(BrandRegistryInterface::class);
        $registry->method('isIntentApprovedNoticeEnabled')->willReturn(true);
        $registry->method('getIntentApprovedNotice')->willReturn('Approved copy for %2.');
        $registry->method('getIntentDeclinedNotice')->willReturn(null);
        $registry->method('getProductName')->willReturn('Acme');

        $reflection = new \ReflectionClass(ConfigProvider::class);
        $provider = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('brandRegistry')->setValue($provider, $registry);

        $declined = $reflection->getMethod('getOrderIntentDeclinedNotice')->invoke($provider);

        $this->assertStringNotContainsString('Approved copy', $declined['withCompany']);
    }

    /**
     * @return array{withCompany:string,withoutCompany:string,companyNameToken:string,companyNumberToken:string}|null
     */
    private function resolveFor(bool $enabled, ?string $override): ?array
    {
        $registry = $this->createMock(BrandRegistryInterface::class);
        $registry->method('isIntentApprovedNoticeEnabled')->willReturn($enabled);
        $registry->method('getIntentDeclinedNotice')->willReturn($override);
        $registry->method('getProductName')->willReturn('Acme');

        $reflection = new \ReflectionClass(ConfigProvider::class);
        $provider = $reflection->newInstanceWithoutConstructor();

        $reflection->getProperty('brandRegistry')->setValue($provider, $registry);

        return $reflection->getMethod('getOrderIntentDeclinedNotice')->invoke($provider);
    }
}
