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
 * ConfigProvider's intent-DECLINED-notice payload resolution. Same
 * suppression switch as the approved notice (isIntentApprovedNoticeEnabled),
 * but — unlike the approved notice — deliberately NO brand copy override
 * (2026-08-04 ruling, TWO-25326): every brand renders the exact same
 * platform default copy for this outcome. BrandRegistryInterface has no
 * getIntentDeclinedNotice() method; do not reintroduce one, and do not add
 * a call to it here.
 */
class ConfigProviderIntentDeclinedNoticeTest extends TestCase
{
    public function testReturnsNullWhenTheBrandDisabledTheNotice(): void
    {
        $payload = $this->resolveFor(false);

        $this->assertNull($payload);
    }

    public function testReturnsPlatformDefaultCopyWhenEnabled(): void
    {
        $payload = $this->resolveFor(true);

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

    public function testApprovedOverrideDoesNotLeakIntoTheDeclinedCopy(): void
    {
        // A brand's approved wording must never bleed into the declined
        // variant — the declined variant has no override input at all.
        $registry = $this->createMock(BrandRegistryInterface::class);
        $registry->method('isIntentApprovedNoticeEnabled')->willReturn(true);
        $registry->method('getIntentApprovedNotice')->willReturn('Approved copy for %2.');
        $registry->method('getProductName')->willReturn('Acme');

        $reflection = new \ReflectionClass(ConfigProvider::class);
        $provider = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('brandRegistry')->setValue($provider, $registry);

        $declined = $reflection->getMethod('getOrderIntentDeclinedNotice')->invoke($provider);

        $this->assertStringNotContainsString('Approved copy', $declined['withCompany']);
    }

    public function testBrandRegistryInterfaceHasNoDeclinedNoticeOverrideHook(): void
    {
        // Locks in the 2026-08-04 ruling at the type level: a brand overlay
        // must never be able to override this copy. If this assertion ever
        // fails, someone re-added the hook — revert it, don't update this
        // test.
        $this->assertFalse(
            method_exists(BrandRegistryInterface::class, 'getIntentDeclinedNotice'),
            'BrandRegistryInterface must not declare a declined-notice copy '
            . 'override; the "order intent NOT approved" message is never '
            . 'brand-overridable.'
        );
    }

    /**
     * @return array{withCompany:string,withoutCompany:string,companyNameToken:string,companyNumberToken:string}|null
     */
    private function resolveFor(bool $enabled): ?array
    {
        $registry = $this->createMock(BrandRegistryInterface::class);
        $registry->method('isIntentApprovedNoticeEnabled')->willReturn($enabled);
        $registry->method('getProductName')->willReturn('Acme');

        $reflection = new \ReflectionClass(ConfigProvider::class);
        $provider = $reflection->newInstanceWithoutConstructor();

        $reflection->getProperty('brandRegistry')->setValue($provider, $registry);

        return $reflection->getMethod('getOrderIntentDeclinedNotice')->invoke($provider);
    }
}
