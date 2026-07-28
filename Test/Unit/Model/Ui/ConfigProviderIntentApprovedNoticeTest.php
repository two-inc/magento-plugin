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
 * ConfigProvider's intent-approved-notice payload resolution (TWO-25218).
 *
 * The switch — not the copy override — decides whether a payload is
 * shipped to the storefront renderer at all. `null` is the renderer's
 * "emit no element" signal, so a regression that keys suppression off the
 * copy override again would re-introduce the superseded three-state
 * contract silently.
 *
 * ConfigProvider's constructor pulls in the full checkout collaborator
 * graph and getConfig() reaches most of it; the notice resolution touches
 * only $brandRegistry. Instantiating without the constructor and injecting
 * that one collaborator keeps this a unit test rather than a checkout
 * harness.
 */
class ConfigProviderIntentApprovedNoticeTest extends TestCase
{
    public function testReturnsNullWhenTheBrandDisabledTheNotice(): void
    {
        $payload = $this->resolveFor(false, null);

        $this->assertNull($payload);
    }

    public function testReturnsNullWhenDisabledEvenWithACopyOverride(): void
    {
        // The switch wins. A brand that declares copy and then turns the
        // notice off must get no payload.
        $payload = $this->resolveFor(false, 'Custom %1 line for %2.');

        $this->assertNull($payload);
    }

    public function testReturnsDefaultCopyWhenEnabledWithNoOverride(): void
    {
        $payload = $this->resolveFor(true, null);

        $this->assertIsArray($payload);
        $this->assertSame(
            'Your invoice with Acme is likely to be accepted for '
            . ConfigProvider::COMPANY_NAME_TOKEN
            . ', subject to additional checks.',
            $payload['withCompany']
        );
        $this->assertSame(
            'Your invoice with Acme is likely to be accepted, subject to additional checks.',
            $payload['withoutCompany']
        );
        $this->assertSame(ConfigProvider::COMPANY_NAME_TOKEN, $payload['companyNameToken']);
    }

    public function testOverrideReplacesTheCompanyKnownVariantOnly(): void
    {
        $payload = $this->resolveFor(true, 'Custom %1 line for %2.');

        $this->assertIsArray($payload);
        $this->assertSame(
            'Custom Acme line for ' . ConfigProvider::COMPANY_NAME_TOKEN . '.',
            $payload['withCompany']
        );
        $this->assertSame(
            'Your invoice with Acme is likely to be accepted, subject to additional checks.',
            $payload['withoutCompany']
        );
    }

    /**
     * @return array{withCompany:string,withoutCompany:string,companyNameToken:string}|null
     */
    private function resolveFor(bool $enabled, ?string $override): ?array
    {
        $registry = $this->createMock(BrandRegistryInterface::class);
        $registry->method('isIntentApprovedNoticeEnabled')->willReturn($enabled);
        $registry->method('getIntentApprovedNotice')->willReturn($override);
        $registry->method('getProductName')->willReturn('Acme');

        $reflection = new \ReflectionClass(ConfigProvider::class);
        $provider = $reflection->newInstanceWithoutConstructor();

        // No setAccessible() call: it has been a no-op since PHP 8.1
        // (the plugin's floor) and is deprecated from 8.5.
        $reflection->getProperty('brandRegistry')->setValue($provider, $registry);

        return $reflection->getMethod('getOrderIntentApprovedNotice')->invoke($provider);
    }
}
