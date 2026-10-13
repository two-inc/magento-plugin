<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Model\Ui;

use Magento\Framework\Phrase;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\BrandRegistryInterface;
use Two\Gateway\Api\Config\RepositoryInterface as ConfigRepository;
use Two\Gateway\Model\Ui\AnchorOnlyHtmlEscaper;
use Two\Gateway\Model\Ui\CheckoutTileCopy;

/**
 * The two brand-supplied checkout link targets. '' means "render
 * nothing", so the empty-URL rows are the ones that matter: an anchor with an
 * empty href would point the buyer at the checkout page itself.
 */
class CheckoutTileCopyTest extends TestCase
{
    private const FAQ_URL = 'https://faq.example.test/invoice';
    private const ABOUT_URL = 'https://about.example.test/what-is-acme';
    private const TAGLINE_KEY = 'For all companies, %1read more%2.';

    protected function tearDown(): void
    {
        Phrase::setRenderer(null);
    }

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
            'admin subtitle carrying a link' => [
                '', self::TAGLINE_KEY, self::FAQ_URL, false,
                'Pay in 30 days, <a href="' . self::FAQ_URL . '" target="_blank" rel="noopener">read more</a>.',
                false, '', 'Pay in 30 days, ' . $anchor . 'read more</a>.',
                'the merchant field may carry a link of its own, and it survives as a link',
            ],
            'admin subtitle set' => [
                '', self::TAGLINE_KEY, self::FAQ_URL, false, '  Pay later & <b>relax</b>  ',
                false, '', 'Pay later &amp; relax',
                'merchant free text replaces the tagline and keeps only what the escaper allows',
            ],
            'admin subtitle of markup around whitespace' => [
                '', self::TAGLINE_KEY, self::FAQ_URL, false, '<b> </b>',
                false, '', 'For all companies, ' . $anchor . 'read more</a>.',
                'copy whose only content is markup the escaper drops is emptiness too, so the tagline still shows',
            ],
            'tagline key carrying stray markup' => [
                '', self::TAGLINE_KEY . '<img src=x onerror="alert(1)">', self::FAQ_URL, false, '',
                false, '', 'For all companies, ' . $anchor . 'read more</a>.',
                'a translation file is merchant-editable copy too, so the tagline goes through the escaper as well',
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

    /**
     * @return array<string, array{0:string,1:bool,2:string,3:string}>
     */
    public static function aboutLinkTextRows(): array
    {
        return [
            'brand about url with the toggle on' => [
                self::ABOUT_URL, true, 'What is Acme Pay?',
                'the accessible name of the icon names the brand product',
            ],
            'no brand about url' => [
                '', true, '',
                'no target means no icon, so there is no name to give one',
            ],
            'brand about url with the toggle off' => [
                self::ABOUT_URL, false, '',
                'the merchant toggle removes the whole control, name included',
            ],
        ];
    }

    /**
     * @dataProvider aboutLinkTextRows
     */
    public function testAboutLinkTextFollowsTheIconItNames(
        string $brandAboutUrl,
        bool $aboutLinkEnabled,
        string $expectedText,
        string $description
    ): void {
        $copy = $this->build($brandAboutUrl, '', '', $aboutLinkEnabled, '');

        $this->assertSame($expectedText, $copy->getAboutLinkText(), $description);
    }

    /**
     * @return array<string, array{0:string,1:bool,2:string,3:string}>
     */
    public static function tooltipRows(): array
    {
        return [
            'brand about url with the toggle on' => [
                self::ABOUT_URL, true, self::tooltipHtml(),
                'the icon is a link, so its tooltip carries the body copy and no anchor of its own',
            ],
            'no brand about url' => [
                '', true, '',
                'no target means no icon, so there is nothing for a tooltip to describe',
            ],
            'brand about url with the toggle off' => [
                self::ABOUT_URL, false, '',
                'the merchant toggle removes the whole control, tooltip included',
            ],
            'non-http about url' => [
                'javascript:alert(1)', true, '',
                'a script URL renders no icon and therefore no tooltip',
            ],
        ];
    }

    /**
     * @dataProvider tooltipRows
     */
    public function testTooltipFollowsTheIconItDescribes(
        string $brandAboutUrl,
        bool $aboutLinkEnabled,
        string $expectedTooltip,
        string $description
    ): void {
        $copy = $this->build($brandAboutUrl, '', '', $aboutLinkEnabled, '');

        $this->assertSame($expectedTooltip, $copy->getAboutTooltipHtml(), $description);
    }

    private static function tooltipHtml(): string
    {
        return '<p>Acme Pay is a payment solution for B2B purchases online, allowing you to buy from your'
            . ' favourite merchants and suppliers on trade credit. Using Acme Pay, you can access flexible'
            . ' trade credit instantly to make purchasing simple.</p>'
            . '<p><strong>Buy now, receive your goods, pay your invoice later.</strong></p>'
            . '<p>Click to find out more</p>';
    }

    /**
     * @return array<string, array{0:string,1:string,2:string,3:string}>
     */
    public static function tooltipTranslationRows(): array
    {
        $body = '%1 is a payment solution for B2B purchases online, allowing you to buy from your favourite'
            . ' merchants and suppliers on trade credit. Using %1, you can access flexible trade credit'
            . ' instantly to make purchasing simple.';

        return [
            'body paragraph' => [
                $body,
                '<img src=x onerror=alert(1)>',
                '<p>&lt;img src=x onerror=alert(1)&gt;</p>',
                'a translated body paragraph cannot open markup, and keeps its own <p>',
            ],
            'emphasised line' => [
                'Buy now, receive your goods, pay your invoice later.',
                '<svg onload=alert(2)></svg>',
                '<p><strong>&lt;svg onload=alert(2)&gt;&lt;/svg&gt;</strong></p>',
                'a translated emphasis line cannot open markup, and keeps its own <p><strong>',
            ],
            'closing line' => [
                'Click to find out more',
                '</p><script>alert(3)</script><p>',
                '<p>&lt;/p&gt;&lt;script&gt;alert(3)&lt;/script&gt;&lt;p&gt;</p>',
                'a translation cannot close the wrapper it was given and open its own',
            ],
            'anchor in a translation' => [
                'Click to find out more',
                '<a href="https://evil.test">click</a>',
                '<p>&lt;a href=&quot;https://evil.test&quot;&gt;click&lt;/a&gt;</p>',
                'the icon is already the link, so a translated anchor is markup rather than a second link',
            ],
        ];
    }

    /**
     * Given an admin-supplied translation carrying markup; when the tooltip renders;
     * then no tag of the translation's survives and the method's own wrappers do.
     *
     * @dataProvider tooltipTranslationRows
     */
    public function testTooltipEscapesTranslatedMarkup(
        string $translatedKey,
        string $payload,
        string $expectedFragment,
        string $description
    ): void {
        Phrase::setRenderer(self::rendererTranslating($translatedKey, $payload));
        $copy = $this->build(self::ABOUT_URL, '', '', true, '');

        $tooltip = $copy->getAboutTooltipHtml();

        $this->assertStringContainsString($expectedFragment, $tooltip, $description);
        $this->assertSame(3, substr_count($tooltip, '<p>'), 'the tooltip lost or gained a wrapper: ' . $description);
    }

    /** The accessible name is plain text, so escaping it would put entities into what a screen reader reads out. */
    public function testAboutLinkTextIsPlainText(): void
    {
        Phrase::setRenderer(self::rendererTranslating('What is %1?', 'Wat is %1 & co?'));

        $text = $this->build(self::ABOUT_URL, '', '', true, '')->getAboutLinkText();

        $this->assertSame('Wat is Acme Pay & co?', $text);
    }

    private static function rendererTranslating(string $key, string $translation): object
    {
        return new class ($key, $translation) implements \Magento\Framework\Phrase\RendererInterface {
            public function __construct(private string $key, private string $translation)
            {
            }

            public function render(array $source, array $arguments): string
            {
                $text = $source[0] === $this->key ? $this->translation : $source[0];
                foreach ($arguments as $index => $value) {
                    $text = str_replace('%' . ($index + 1), (string)$value, $text);
                }

                return $text;
            }
        };
    }

    public function testTooltipEscapesTheBrandName(): void
    {
        $copy = $this->build(self::ABOUT_URL, '', '', true, '', '<b>Acme</b> & Pay');

        $tooltip = $copy->getAboutTooltipHtml();

        $this->assertStringContainsString('&lt;b&gt;Acme&lt;/b&gt; &amp; Pay is a payment solution', $tooltip);
        $this->assertStringNotContainsString('<b>Acme</b>', $tooltip);
    }

    private function build(
        string $brandAboutUrl,
        string $brandTaglineKey,
        string $brandFaqUrl,
        bool $aboutLinkEnabled,
        string $adminSubtitle,
        string $productName = 'Acme Pay'
    ): CheckoutTileCopy {
        $configRepository = $this->createMock(ConfigRepository::class);
        $configRepository->method('isAboutLinkEnabled')->willReturn($aboutLinkEnabled);
        $configRepository->method('getSubtitle')->willReturn($adminSubtitle);

        $brandRegistry = $this->createMock(BrandRegistryInterface::class);
        $brandRegistry->method('getProductName')->willReturn($productName);
        $brandRegistry->method('getAboutUrl')->willReturn($brandAboutUrl);
        $brandRegistry->method('getCheckoutSubtitle')->willReturn($brandTaglineKey);
        $brandRegistry->method('getCheckoutSubtitleFaqUrl')->willReturn($brandFaqUrl);

        return new CheckoutTileCopy($configRepository, $brandRegistry, new AnchorOnlyHtmlEscaper());
    }
}
