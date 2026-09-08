<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Model\Brand;

use Magento\Framework\Component\ComponentRegistrar;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Model\Brand\Loader;

/**
 * Focused on the brand.xml -> Descriptor mapping for elements whose
 * per-state behaviour is load-bearing:
 *
 *  - <surcharge_rounding_steps> — admin Rounding step dropdown; absent
 *    and empty both fall back to the parent default set.
 *  - <intent_approved_notice_enabled> — on/off switch for the buyer-facing
 *    intent-approved notice (TWO-25218); explicit boolean, absent means
 *    the documented default true, anything else must throw rather than
 *    become a silent third behaviour.
 *  - <intent_approved_notice> — copy override for the same notice; every
 *    visually blank value is INERT (they used to mean "off" under the
 *    superseded TWO-25213 three-state contract).
 *  - <intent_declined_notice_enabled> / <intent_declined_notice> — the same
 *    pair for the "order intent NOT approved" outcome (TWO-25326). A
 *    declared switch decides; absent one, the notice renders when
 *    non-blank declined copy or the approved switch says so.
 *
 * Loader does no runtime XSD validation, so the parse/validate guards
 * here are the only safety net.
 */
class LoaderTest extends TestCase
{
    /** @var string[] dirs to clean up */
    private array $tmpDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tmpDirs as $dir) {
            @unlink($dir . '/etc/brand.xml');
            @rmdir($dir . '/etc');
            @rmdir($dir);
        }
        $this->tmpDirs = [];
    }

    public function testRoundingStepsAreParsedDedupedAndSortedAscending(): void
    {
        $loader = $this->loaderForBrandBody(
            '<surcharge_rounding_steps>'
            . '<step>1.00</step><step>0.50</step><step>0.50</step>'
            . '<step>0.10</step><step>5</step>'
            . '</surcharge_rounding_steps>'
        );

        $descriptor = $loader->load()['two_payment'];

        // 0.5 == 0.50 collapses; ascending numeric order.
        $this->assertSame([0.1, 0.5, 1.0, 5.0], $descriptor->getSurchargeRoundingSteps());
    }

    public function testRoundingStepsFallBackToDefaultWhenElementAbsent(): void
    {
        $loader = $this->loaderForBrandBody('');

        $descriptor = $loader->load()['two_payment'];

        $this->assertSame(
            [0.1, 0.5, 1.0, 5.0, 10.0],
            $descriptor->getSurchargeRoundingSteps()
        );
    }

    public function testRoundingStepsFallBackToDefaultWhenElementEmpty(): void
    {
        $loader = $this->loaderForBrandBody(
            '<surcharge_rounding_steps></surcharge_rounding_steps>'
        );

        $descriptor = $loader->load()['two_payment'];

        $this->assertSame(
            [0.1, 0.5, 1.0, 5.0, 10.0],
            $descriptor->getSurchargeRoundingSteps()
        );
    }

    public function testIntentApprovedNoticeEnabledIsTrueWhenDeclaredTrue(): void
    {
        $loader = $this->loaderForBrandBody(
            '<intent_approved_notice_enabled>true</intent_approved_notice_enabled>'
        );

        $this->assertTrue(
            $loader->load()['two_payment']->isIntentApprovedNoticeEnabled()
        );
    }

    public function testIntentApprovedNoticeEnabledIsFalseWhenDeclaredFalse(): void
    {
        $loader = $this->loaderForBrandBody(
            '<intent_approved_notice_enabled>false</intent_approved_notice_enabled>'
        );

        $this->assertFalse(
            $loader->load()['two_payment']->isIntentApprovedNoticeEnabled()
        );
    }

    public function testIntentApprovedNoticeEnabledDefaultsToTrueWhenElementAbsent(): void
    {
        $loader = $this->loaderForBrandBody('');

        // Absent is the documented explicit default true — this is what
        // keeps a third-party overlay that declares nothing on ON.
        $this->assertTrue(
            $loader->load()['two_payment']->isIntentApprovedNoticeEnabled()
        );
    }

    public function testIntentApprovedNoticeEnabledIsSurroundingWhitespaceTolerant(): void
    {
        $loader = $this->loaderForBrandBody(
            "<intent_approved_notice_enabled>\n   false\n  </intent_approved_notice_enabled>"
        );

        // A pretty-printed value is still an explicit decision, not a
        // malformed one.
        $this->assertFalse(
            $loader->load()['two_payment']->isIntentApprovedNoticeEnabled()
        );
    }

    /**
     * Every non-`true`/`false` spelling must be an error, never a silent
     * third behaviour — including the ones xs:boolean would have accepted
     * (`1` / `0`) and the empty element that used to mean "off".
     *
     * @dataProvider invalidNoticeEnabledProvider
     */
    public function testInvalidIntentApprovedNoticeEnabledThrows(string $value): void
    {
        $loader = $this->loaderForBrandBody(
            '<intent_approved_notice_enabled>' . $value . '</intent_approved_notice_enabled>'
        );

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('invalid <intent_approved_notice_enabled> value');
        $loader->load();
    }

    /** @return array<string,array{0:string}> */
    public static function invalidNoticeEnabledProvider(): array
    {
        return [
            'numeric one' => ['1'],
            'numeric zero' => ['0'],
            'yes' => ['yes'],
            'title case' => ['True'],
            'upper case' => ['FALSE'],
            'empty' => [''],
            'whitespace only' => ["\n   "],
        ];
    }

    public function testIntentApprovedNoticeCopyIsNullWhenElementAbsent(): void
    {
        $loader = $this->loaderForBrandBody('');

        $this->assertNull($loader->load()['two_payment']->getIntentApprovedNotice());
    }

    public function testIntentApprovedNoticeCopyIsNullWhenElementPresentAndEmpty(): void
    {
        $loader = $this->loaderForBrandBody(
            '<intent_approved_notice></intent_approved_notice>'
        );

        // Empty is INERT, not "off" — it must never surface as '', which is
        // what the superseded three-state contract used as its off signal.
        $this->assertNull($loader->load()['two_payment']->getIntentApprovedNotice());
    }

    public function testIntentApprovedNoticeCopyIsNullWhenElementSelfClosing(): void
    {
        $loader = $this->loaderForBrandBody('<intent_approved_notice/>');

        $this->assertNull($loader->load()['two_payment']->getIntentApprovedNotice());
    }

    public function testIntentApprovedNoticeCopyIsNullWhenWhitespaceOnly(): void
    {
        $loader = $this->loaderForBrandBody(
            "<intent_approved_notice>\n            </intent_approved_notice>"
        );

        // A pretty-printed empty element must not become a whitespace
        // template that renders as a blank notice.
        $this->assertNull($loader->load()['two_payment']->getIntentApprovedNotice());
    }

    public function testIntentApprovedNoticeCopyIsUsedVerbatimWhenNonEmpty(): void
    {
        $loader = $this->loaderForBrandBody(
            '<intent_approved_notice>%1 says %2 looks fine.</intent_approved_notice>'
        );

        $this->assertSame(
            '%1 says %2 looks fine.',
            $loader->load()['two_payment']->getIntentApprovedNotice()
        );
    }

    public function testCopyOverrideDoesNotSuppressAndSwitchDoesNotChangeCopy(): void
    {
        // The two keys are independent: a brand can suppress the notice
        // while still shipping copy, and the loader must not let either
        // decision leak into the other.
        $loader = $this->loaderForBrandBody(
            '<intent_approved_notice_enabled>false</intent_approved_notice_enabled>'
            . '<intent_approved_notice>%1 says %2 looks fine.</intent_approved_notice>'
        );

        $descriptor = $loader->load()['two_payment'];

        $this->assertFalse($descriptor->isIntentApprovedNoticeEnabled());
        $this->assertSame('%1 says %2 looks fine.', $descriptor->getIntentApprovedNotice());
    }

    /**
     * @dataProvider declinedNoticeSwitchProvider
     */
    public function testDeclinedNoticeSwitchResolution(
        string $extraXml,
        bool $expected,
        string $case
    ): void {
        $loader = $this->loaderForBrandBody($extraXml);

        $this->assertSame(
            $expected,
            $loader->load()['two_payment']->isIntentDeclinedNoticeEnabled(),
            $case
        );
    }

    /** @return array<string,array{0:string,1:bool,2:string}> */
    public static function declinedNoticeSwitchProvider(): array
    {
        return [
            'declared true' => [
                '<intent_declined_notice_enabled>true</intent_declined_notice_enabled>',
                true,
                'an explicit true keeps the declined notice ON',
            ],
            'declared false' => [
                '<intent_declined_notice_enabled>false</intent_declined_notice_enabled>',
                false,
                'an explicit false suppresses the declined notice',
            ],
            'absent, approved absent' => [
                '',
                true,
                'both absent is the documented default true',
            ],
            'surrounding whitespace' => [
                "<intent_declined_notice_enabled>\n  false\n </intent_declined_notice_enabled>",
                false,
                'a pretty-printed value is still an explicit decision',
            ],
            'absent, approved false' => [
                '<intent_approved_notice_enabled>false</intent_approved_notice_enabled>',
                false,
                'an overlay predating the declined element keeps suppressing both',
            ],
            // Documentation row: passes under the inheritance and under a
            // plain default-true, so it pins the contract, not the mechanism.
            'absent, approved true (documentation row)' => [
                '<intent_approved_notice_enabled>true</intent_approved_notice_enabled>',
                true,
                'the inheritance follows the approved switch, so true renders',
            ],
            'switch declared false, copy non-blank' => [
                '<intent_declined_notice_enabled>false</intent_declined_notice_enabled>'
                . '<intent_declined_notice>%1 cannot cover %2 (%3).</intent_declined_notice>',
                false,
                'a declared switch outranks the copy-implies-on rule',
            ],
            'copy declared, approved false' => [
                '<intent_approved_notice_enabled>false</intent_approved_notice_enabled>'
                . '<intent_declined_notice>%1 cannot cover %2 (%3).</intent_declined_notice>',
                true,
                'non-blank declined copy is intent to render, outranking the approved switch',
            ],
            'empty copy declared, approved false' => [
                '<intent_approved_notice_enabled>false</intent_approved_notice_enabled>'
                . '<intent_declined_notice></intent_declined_notice>',
                false,
                'an inert empty copy element does not resolve the switch',
            ],
            'nbsp-only copy declared, approved false' => [
                '<intent_approved_notice_enabled>false</intent_approved_notice_enabled>'
                . '<intent_declined_notice>&#160;</intent_declined_notice>',
                false,
                'a non-breaking space is whitespace, so it cannot resolve the switch',
            ],
            'zero-width-space-only copy declared, approved false' => [
                '<intent_approved_notice_enabled>false</intent_approved_notice_enabled>'
                . '<intent_declined_notice>&#8203;</intent_declined_notice>',
                false,
                'a zero-width space renders nothing, so it cannot resolve the switch',
            ],
            'declared false, approved true' => [
                '<intent_approved_notice_enabled>true</intent_approved_notice_enabled>'
                . '<intent_declined_notice_enabled>false</intent_declined_notice_enabled>',
                false,
                'an explicit declined false overrides the approved true',
            ],
            'declared true, approved false' => [
                '<intent_approved_notice_enabled>false</intent_approved_notice_enabled>'
                . '<intent_declined_notice_enabled>true</intent_declined_notice_enabled>',
                true,
                'an explicit declined true overrides the approved false',
            ],
        ];
    }

    /**
     * @dataProvider invalidDeclinedNoticeEnabledProvider
     */
    public function testInvalidIntentDeclinedNoticeEnabledThrows(string $value): void
    {
        $loader = $this->loaderForBrandBody(
            '<intent_declined_notice_enabled>' . $value . '</intent_declined_notice_enabled>'
        );

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('invalid <intent_declined_notice_enabled> value');
        $loader->load();
    }

    /** @return array<string,array{0:string}> */
    public static function invalidDeclinedNoticeEnabledProvider(): array
    {
        return self::invalidNoticeEnabledProvider();
    }

    /**
     * @dataProvider declinedNoticeCopyProvider
     */
    public function testDeclinedNoticeCopyResolution(
        string $extraXml,
        ?string $expected,
        string $case
    ): void {
        $loader = $this->loaderForBrandBody($extraXml);

        $this->assertSame(
            $expected,
            $loader->load()['two_payment']->getIntentDeclinedNotice(),
            $case
        );
    }

    /** @return array<string,array{0:string,1:?string,2:string}> */
    public static function declinedNoticeCopyProvider(): array
    {
        return [
            'absent' => [
                '',
                null,
                'absent means the platform default copy',
            ],
            'empty' => [
                '<intent_declined_notice></intent_declined_notice>',
                null,
                'an empty element is inert, never a blank notice',
            ],
            'self closing' => [
                '<intent_declined_notice/>',
                null,
                'a self-closing element is inert',
            ],
            'whitespace only' => [
                "<intent_declined_notice>\n   </intent_declined_notice>",
                null,
                'whitespace-only is inert',
            ],
            'nbsp only' => [
                '<intent_declined_notice>&#160;&#160;</intent_declined_notice>',
                null,
                'non-breaking spaces are inert, never a blank template',
            ],
            'zero-width space only' => [
                '<intent_declined_notice>&#8203;</intent_declined_notice>',
                null,
                'a zero-width space is inert, never a blank template',
            ],
            'non empty' => [
                '<intent_declined_notice>%1 cannot cover %2 (%3).</intent_declined_notice>',
                '%1 cannot cover %2 (%3).',
                'non-blank copy is taken verbatim',
            ],
            'approved copy does not leak' => [
                '<intent_approved_notice>%1 says %2 looks fine.</intent_approved_notice>',
                null,
                'the approved copy override is not the declined one',
            ],
        ];
    }

    /**
     * @dataProvider invalidStepProvider
     */
    public function testInvalidRoundingStepThrows(string $stepValue): void
    {
        $loader = $this->loaderForBrandBody(
            '<surcharge_rounding_steps><step>' . $stepValue . '</step></surcharge_rounding_steps>'
        );

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('invalid surcharge rounding step');
        $loader->load();
    }

    /** @return array<string,array{0:string}> */
    public static function invalidStepProvider(): array
    {
        return [
            'non-numeric' => ['abc'],
            'zero' => ['0'],
            'negative' => ['-1.00'],
        ];
    }

    /**
     * @param string $extraXml Optional element(s) spliced into the <brand>
     *                         body under test.
     */
    private function loaderForBrandBody(string $extraXml): Loader
    {
        $dir = sys_get_temp_dir() . '/two_brand_test_' . uniqid('', true);
        mkdir($dir . '/etc', 0777, true);
        $this->tmpDirs[] = $dir;

        $xml = '<?xml version="1.0"?>'
            . '<config><brand code="two_payment" section_prefix="two" tab_sort_order="500">'
            . '<provider>Two</provider><product_name>Two</product_name>'
            . '<tab_label>Two</tab_label>'
            . '<checkout_url_template>https://%s.two.inc</checkout_url_template>'
            . '<api_base_url>https://api.two.inc</api_base_url>'
            . '<available_payment_terms><term>30</term></available_payment_terms>'
            . $extraXml
            . '<admin_resource>Magento_Sales::config_sales</admin_resource>'
            . '</brand></config>';
        file_put_contents($dir . '/etc/brand.xml', $xml);

        $registrar = $this->createMock(ComponentRegistrar::class);
        $registrar->method('getPaths')
            ->with(ComponentRegistrar::MODULE)
            ->willReturn(['Two_Gateway' => $dir]);

        return new Loader($registrar);
    }
}
