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
 * ConfigProvider's intent-DECLINED-notice payload resolution.
 *
 * TWO-25326: a brand overlay may reword the declined notice or suppress
 * it, on its own switch and its own copy override, exactly as it may for
 * the approved notice. The switch — not the copy — decides whether a
 * payload reaches the renderer at all; `null` is the renderer's "emit no
 * element" signal.
 */
class ConfigProviderIntentDeclinedNoticeTest extends TestCase
{
    private const DEFAULT_WITH_COMPANY = 'Acme is not available for this order by '
        . ConfigProvider::COMPANY_NAME_TOKEN
        . ' (' . ConfigProvider::COMPANY_NUMBER_TOKEN . ')';

    /**
     * @dataProvider declinedNoticeProvider
     */
    public function testDeclinedNoticeResolution(
        bool $enabled,
        ?string $override,
        ?string $expectedWithCompany,
        string $case
    ): void {
        $payload = $this->resolveFor($enabled, $override);

        if ($expectedWithCompany === null) {
            $this->assertNull($payload, $case);
            return;
        }

        $this->assertIsArray($payload, $case);
        $this->assertSame($expectedWithCompany, $payload['withCompany'], $case);
        $this->assertSame(
            'Acme is not available for this order',
            $payload['withoutCompany'],
            $case
        );
        $this->assertSame(ConfigProvider::COMPANY_NAME_TOKEN, $payload['companyNameToken'], $case);
        $this->assertSame(
            ConfigProvider::COMPANY_NUMBER_TOKEN,
            $payload['companyNumberToken'],
            $case
        );
    }

    /** @return array<string,array{0:bool,1:?string,2:?string,3:string}> */
    public static function declinedNoticeProvider(): array
    {
        return [
            'enabled, no override' => [
                true,
                null,
                self::DEFAULT_WITH_COMPANY,
                'no override leaves the platform default copy',
            ],
            'enabled, override' => [
                true,
                '%1 cannot cover %2 (%3).',
                'Acme cannot cover '
                . ConfigProvider::COMPANY_NAME_TOKEN
                . ' (' . ConfigProvider::COMPANY_NUMBER_TOKEN . ').',
                'a brand override replaces the company-known wording',
            ],
            'suppressed' => [
                false,
                null,
                null,
                'the switch off means no payload at all',
            ],
            'suppressed despite override' => [
                false,
                '%1 cannot cover %2 (%3).',
                null,
                'the switch wins over non-blank copy',
            ],
        ];
    }

    public function testApprovedOverrideDoesNotLeakIntoTheDeclinedCopy(): void
    {
        // The two copy overrides are separate inputs; a brand that reworded
        // only the approved notice keeps the default declined wording.
        $registry = $this->createMock(BrandRegistryInterface::class);
        $registry->method('isIntentDeclinedNoticeEnabled')->willReturn(true);
        $registry->method('getIntentDeclinedNotice')->willReturn(null);
        $registry->method('isIntentApprovedNoticeEnabled')->willReturn(true);
        $registry->method('getIntentApprovedNotice')->willReturn('Approved copy for %2.');
        $registry->method('getProductName')->willReturn('Acme');

        $declined = $this->invokeWith($registry);

        $this->assertSame(self::DEFAULT_WITH_COMPANY, $declined['withCompany']);
    }

    public function testTheApprovedSwitchDoesNotSuppressTheDeclinedNotice(): void
    {
        // The two switches are independent: suppressing the approved
        // notice is not a decision about the declined one.
        $registry = $this->createMock(BrandRegistryInterface::class);
        $registry->method('isIntentDeclinedNoticeEnabled')->willReturn(true);
        $registry->method('getIntentDeclinedNotice')->willReturn(null);
        $registry->method('isIntentApprovedNoticeEnabled')->willReturn(false);
        $registry->method('getProductName')->willReturn('Acme');

        $this->assertIsArray($this->invokeWith($registry));
    }

    /**
     * @return array{withCompany:string,withoutCompany:string,companyNameToken:string,companyNumberToken:string}|null
     */
    private function resolveFor(bool $enabled, ?string $override): ?array
    {
        $registry = $this->createMock(BrandRegistryInterface::class);
        $registry->method('isIntentDeclinedNoticeEnabled')->willReturn($enabled);
        $registry->method('getIntentDeclinedNotice')->willReturn($override);
        $registry->method('getProductName')->willReturn('Acme');

        return $this->invokeWith($registry);
    }

    /**
     * @return array{withCompany:string,withoutCompany:string,companyNameToken:string,companyNumberToken:string}|null
     */
    private function invokeWith(BrandRegistryInterface $registry): ?array
    {
        $reflection = new \ReflectionClass(ConfigProvider::class);
        $provider = $reflection->newInstanceWithoutConstructor();

        $reflection->getProperty('brandRegistry')->setValue($provider, $registry);

        return $reflection->getMethod('getOrderIntentDeclinedNotice')->invoke($provider);
    }
}
