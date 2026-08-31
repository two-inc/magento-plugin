<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Plugin\Config\Structure\Reader;

use Magento\Config\Model\Config\Structure\Converter;
use Magento\Config\Model\Config\Structure\Reader;
use Magento\Framework\Module\Dir;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Two\Gateway\Model\Brand\Descriptor;
use Two\Gateway\Model\Brand\Loader;
use Two\Gateway\Plugin\Magento\Config\Model\Config\Structure\Reader\SynthesiseBrandAdminForm;

/**
 * TWO-25386 follow-up: `{{provider}}` is substituted into
 * `etc/adminhtml/brand_form_template.xml`'s admin <comment>/<label>
 * strings so a partner brand overlay sees its own name in those
 * captions instead of a hardcoded "Two".
 *
 * These tests load the REAL template file (via a Dir stub pointed at this
 * module's own etc/ dir) and drive it through
 * `SynthesiseBrandAdminForm::afterRead` with a stubbed Loader and a
 * Converter double that just hands back the substituted DOM so the test
 * can inspect it directly — Magento's own Converter::convert() is exercised
 * elsewhere (it's core Magento code, not ours); what this test proves is
 * that OUR substitution reaches the DOM correctly for an arbitrary brand,
 * not just the base "Two" one. That is also the harness this repo has for
 * simulating an overlay's brand value: there's no brand-specific fixture
 * in this repo (a partner overlay's own Descriptor lives in its own
 * overlay module), so a directly-constructed Descriptor with a
 * hypothetical provider name is the straightforward substitute the task
 * asked for.
 */
class SynthesiseBrandAdminFormProviderTokenTest extends TestCase
{
    /**
     * @dataProvider providerNames
     */
    public function testProviderTokenResolvesInAdminCaptions(string $providerName): void
    {
        $dom = $this->renderTemplateForProvider($providerName);
        $xpath = new \DOMXPath($dom);

        $showAboutLinkLabel = $xpath->query(
            '//section[@id="brandx_checkout_fields"]//field[@id="show_about_link"]/label'
        )->item(0);
        self::assertNotNull($showAboutLinkLabel, 'show_about_link field must exist in the synthesised structure');
        self::assertSame(
            sprintf('Show "What is %s" link', $providerName),
            $showAboutLinkLabel->textContent
        );

        $showAboutLinkComment = $xpath->query(
            '//section[@id="brandx_checkout_fields"]//field[@id="show_about_link"]/comment'
        )->item(0);
        self::assertSame(
            sprintf("Show a link at checkout explaining %s's buy-now-pay-later offering to the buyer.", $providerName),
            $showAboutLinkComment->textContent
        );

        $vendorSiteNameComment = $xpath->query(
            '//section[@id="brandx_general"]//field[@id="vendor_site_name"]/comment'
        )->item(0);
        self::assertStringContainsString(
            sprintf('sharing the same %s merchant account', $providerName),
            $vendorSiteNameComment->textContent
        );
        self::assertStringContainsString(
            sprintf('every order %s receives', $providerName),
            $vendorSiteNameComment->textContent
        );

        $sslComment = $xpath->query(
            '//section[@id="brandx_version"]//field[@id="disable_ssl_verify"]/comment'
        )->item(0);
        self::assertStringContainsString(
            sprintf('outbound calls to the %s API', $providerName),
            $sslComment->textContent
        );

        // No leftover token anywhere in the rendered document, for any brand.
        $xml = $dom->saveXML();
        self::assertStringNotContainsString('{{provider}}', $xml);
        self::assertStringNotContainsString('{{provider_cdata}}', $xml);
    }

    /**
     * Regression: a field added to system.xml with no matching entry in
     * brand_form_template.xml is silently dropped by the merge
     * (SynthesiseBrandAdminForm::afterRead drops any system.xml field the
     * template doesn't already declare) — invisible in the admin UI on
     * every environment, not just uncached.
     */
    public function testFirewallAndRateLimitFieldsSurviveSynthesis(): void
    {
        $dom = $this->renderTemplateForProvider('Two');
        $xpath = new \DOMXPath($dom);

        foreach (
            [
                'brandx_general' => ['firewall_token', 'firewall_token_browser', 'trusted_proxies'],
                'brandx_version' => ['disable_rate_limit'],
            ] as $sectionId => $fieldIds
        ) {
            foreach ($fieldIds as $fieldId) {
                $node = $xpath->query(
                    sprintf('//section[@id="%s"]//field[@id="%s"]', $sectionId, $fieldId)
                )->item(0);
                self::assertNotNull($node, sprintf('%s must exist in section %s', $fieldId, $sectionId));
            }
        }
    }

    /**
     * The disable_ssl_verify comment is a CDATA section, which the XML
     * parser never entity-decodes. A provider name containing "&" must
     * come through literally ("Smith & Co."), NOT as an entity-escaped
     * "Smith &amp;amp; Co." — which is what would happen if this site
     * used the entity-escaped {{provider}} substitution instead of the
     * raw {{provider_cdata}} one. This is the regression Han's review
     * (round 1) caught: legal/partner entity names routinely contain
     * "&", so this was not a hypothetical edge case.
     */
    public function testProviderCdataSiteHandlesAmpersandLiterally(): void
    {
        $dom = $this->renderTemplateForProvider('Smith & Co.');
        $xpath = new \DOMXPath($dom);

        $sslComment = $xpath->query(
            '//section[@id="brandx_version"]//field[@id="disable_ssl_verify"]/comment'
        )->item(0);
        self::assertNotNull($sslComment);
        self::assertStringContainsString(
            'outbound calls to the Smith & Co. API',
            $sslComment->textContent,
            'a "&" in the provider name must render literally inside the CDATA comment, not as an escaped entity'
        );
    }

    /**
     * @return array<string, array{0: string}>
     */
    public function providerNames(): array
    {
        return [
            'base brand' => ['Two'],
            'hypothetical overlay brand' => ['Acme Corp'],
        ];
    }

    private function renderTemplateForProvider(string $providerName): \DOMDocument
    {
        $descriptor = $this->makeDescriptor($providerName);

        $loader = $this->createMock(Loader::class);
        $loader->method('load')->willReturn([$descriptor]);

        /** @var \DOMDocument|null $captured */
        $captured = null;
        $converter = $this->createMock(Converter::class);
        $converter->method('convert')->willReturnCallback(
            function (\DOMDocument $dom) use (&$captured): array {
                $captured = $dom;
                // Satisfy SynthesiseBrandAdminForm's "has config.system root"
                // check without needing Magento's real Converter behaviour.
                return ['config' => ['system' => ['sections' => [], 'tabs' => []]]];
            }
        );

        $moduleDir = $this->createMock(Dir::class);
        $moduleDir->method('getDir')->willReturn($this->moduleEtcDir());

        $logger = $this->createMock(LoggerInterface::class);

        $sut = new SynthesiseBrandAdminForm($loader, $converter, $moduleDir, $logger);
        $sut->afterRead(
            $this->createMock(Reader::class),
            ['config' => ['system' => ['sections' => [], 'tabs' => []]]]
        );

        self::assertNotNull($captured, 'Converter::convert must have been invoked with the substituted DOM');
        return $captured;
    }

    private function makeDescriptor(string $providerName): Descriptor
    {
        return new Descriptor(
            code: 'brandx_payment',
            sectionPrefix: 'brandx',
            tabSortOrder: 500,
            provider: $providerName,
            providerFullName: $providerName,
            productName: $providerName,
            tabLabel: $providerName,
            tabCssClass: 'brandx-extension',
            checkoutUrlTemplate: 'https://%s.example.test',
            brandTag: '',
            signUpUrl: 'https://example.test/signup',
            documentationUrl: 'https://example.test/docs',
            apiBaseUrl: 'https://api.example.test',
            cspOrigins: [],
            adminResource: 'Magento_Sales::config_sales',
            moduleLabelChain: [],
            extraHttpHeaders: []
        );
    }

    private function moduleEtcDir(): string
    {
        // This test file lives at
        // Test/Unit/Plugin/Config/Structure/Reader/<this file> — six levels
        // up is the module root, matching the pattern already used by
        // SynthesiseBrandAdminFormTest for etc/config.xml.
        return dirname(__DIR__, 6) . '/etc';
    }
}
