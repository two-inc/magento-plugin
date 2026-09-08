<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Model\Ui;

use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\BrandRegistryInterface;
use Two\Gateway\Api\Config\RepositoryInterface as ConfigRepository;
use Two\Gateway\Model\Ui\CheckoutTileCopy;

/**
 * The two brand-supplied checkout link targets (ABN-496). '' means "render
 * nothing", so the empty-URL rows are the ones that matter: an anchor with an
 * empty href would point the buyer at the checkout page itself.
 */
class CheckoutTileCopyTest extends TestCase
{
    private const FAQ_URL = 'https://faq.example.test/invoice';
    private const ABOUT_URL = 'https://about.example.test/what-is-acme';
    private const TAGLINE_KEY = 'For all companies, %1read more%2.';

    /**
     * @return array<string, array{0:string,1:string,2:string,3:bool,4:string,5:bool,6:string,7:string,8:string}>
     */
    public static function tileCopyRows(): array
    {
        $anchor = '<a href="' . self::FAQ_URL . '" target="_blank" rel="noopener">';

        return [
            // brand about url, brand tagline key, brand faq url, toggle, admin subtitle,
            // expected visible, expected url, expected subtitle, description
            'brand about url with the toggle on' => [
                self::ABOUT_URL, '', '', true, '',
                true, self::ABOUT_URL, '',
                'the brand URL reaches the renderer verbatim',
            ],
            'no brand about url with the toggle on' => [
                '', '', '', true, '',
                false, '', '',
                'an enabled toggle must not resurrect a link with no target',
            ],
            'brand about url with the toggle off' => [
                self::ABOUT_URL, '', '', false, '',
                false, '', '',
                'the merchant toggle hides the link and withholds its target',
            ],
            'tagline key and faq url, blank admin subtitle' => [
                '', self::TAGLINE_KEY, self::FAQ_URL, false, '',
                false, '', 'For all companies, ' . $anchor . 'read more</a>.',
                'the brand key supplies the sentence and the brand URL its link args',
            ],
            'tagline key but no faq url' => [
                '', self::TAGLINE_KEY, '', false, '',
                false, '', '',
                'no FAQ target means no tagline at all rather than a dead link',
            ],
            'faq url but no tagline key' => [
                '', '', self::FAQ_URL, false, '',
                false, '', '',
                'a brand that declares no tagline gets none',
            ],
            'non-http about url' => [
                'javascript:alert(1)', '', '', true, '',
                false, '', '',
                'only http(s) is a link target, so a script URL renders no link',
            ],
            'non-http faq url' => [
                '', self::TAGLINE_KEY, 'javascript:alert(1)', false, '',
                false, '', '',
                'a script URL in the tagline renders no tagline',
            ],
            'admin subtitle set' => [
                '', self::TAGLINE_KEY, self::FAQ_URL, false, '  Pay later & <b>relax</b>  ',
                false, '', 'Pay later &amp; &lt;b&gt;relax&lt;/b&gt;',
                'merchant free text is escaped and never carries a read-more link',
            ],
        ];
    }

    /**
     * @dataProvider tileCopyRows
     */
    public function testTileCopyResolvesFromBrandDataAndMerchantConfig(
        string $brandAboutUrl,
        string $brandTaglineKey,
        string $brandFaqUrl,
        bool $aboutLinkEnabled,
        string $adminSubtitle,
        bool $expectedVisible,
        string $expectedUrl,
        string $expectedSubtitle,
        string $description
    ): void {
        $copy = $this->build($brandAboutUrl, $brandTaglineKey, $brandFaqUrl, $aboutLinkEnabled, $adminSubtitle);

        $this->assertSame($expectedVisible, $copy->isAboutLinkVisible(), $description);
        $this->assertSame($expectedUrl, $copy->getAboutLinkUrl(), $description);
        $this->assertSame($expectedSubtitle, $copy->getSubtitleHtml(), $description);
    }

    public function testAboutLinkTextNamesTheBrandProduct(): void
    {
        $copy = $this->build(self::ABOUT_URL, '', '', true, '');

        $this->assertSame('What is Acme Pay?', $copy->getAboutLinkText());
    }

    private function build(
        string $brandAboutUrl,
        string $brandTaglineKey,
        string $brandFaqUrl,
        bool $aboutLinkEnabled,
        string $adminSubtitle
    ): CheckoutTileCopy {
        $configRepository = $this->createMock(ConfigRepository::class);
        $configRepository->method('isAboutLinkEnabled')->willReturn($aboutLinkEnabled);
        $configRepository->method('getSubtitle')->willReturn($adminSubtitle);

        $brandRegistry = $this->createMock(BrandRegistryInterface::class);
        $brandRegistry->method('getProductName')->willReturn('Acme Pay');
        $brandRegistry->method('getAboutUrl')->willReturn($brandAboutUrl);
        $brandRegistry->method('getCheckoutSubtitle')->willReturn($brandTaglineKey);
        $brandRegistry->method('getCheckoutSubtitleFaqUrl')->willReturn($brandFaqUrl);

        return new CheckoutTileCopy($configRepository, $brandRegistry);
    }
}
