<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Model\Ui;

use PHPUnit\Framework\TestCase;
use Two\Gateway\Model\Ui\AnchorOnlyHtmlEscaper;

/**
 * The checkout tile binds the subtitle unescaped, so this escaper is the whole
 * trust boundary between brand/merchant copy and the buyer's page.
 */
class AnchorOnlyHtmlEscaperTest extends TestCase
{
    private const URL = 'https://faq.example.test/x';

    /** @return array<string, array{0:string,1:string,2:string}> */
    public static function escapingRows(): array
    {
        return [
            'bare sentence' => [
                'For all companies, read more.',
                'For all companies, read more.',
                'copy with no markup is untouched',
            ],
            'permitted anchor' => [
                'For all companies, <a href="' . self::URL . '" target="_blank" rel="noopener">read more</a>.',
                'For all companies, <a href="' . self::URL . '" target="_blank" rel="noopener">read more</a>.',
                'the anchor this module emits survives verbatim',
            ],
            'anchor with onclick' => [
                '<a href="' . self::URL . '" onclick="steal()">read more</a>',
                '<a href="' . self::URL . '">read more</a>',
                'an event handler never reaches the page',
            ],
            'anchor with style' => [
                '<a href="' . self::URL . '" style="position:fixed;inset:0">read more</a>',
                '<a href="' . self::URL . '">read more</a>',
                'styling cannot turn the link into an overlay',
            ],
            'anchor with class' => [
                '<a href="' . self::URL . '" class="btn">read more</a>',
                '<a href="' . self::URL . '">read more</a>',
                'copy cannot borrow the theme\'s classes',
            ],
            'anchor with download' => [
                '<a href="' . self::URL . '" download="invoice.pdf">read more</a>',
                '<a href="' . self::URL . '">read more</a>',
                'the link cannot be turned into a download',
            ],
            'anchor with a foreign target' => [
                '<a href="' . self::URL . '" target="_top">read more</a>',
                '<a href="' . self::URL . '">read more</a>',
                'only the _blank this module emits is kept',
            ],
            'anchor with a foreign rel' => [
                '<a href="' . self::URL . '" rel="me">read more</a>',
                '<a href="' . self::URL . '">read more</a>',
                'only the noopener this module emits is kept',
            ],
            'javascript href' => [
                '<a href="javascript:alert(1)">read more</a>',
                'read more',
                'a script URL loses the anchor and keeps the text',
            ],
            'javascript href containing an https URL' => [
                '<a href="javascript:x=\'https://ok.example\'">read more</a>',
                'read more',
                'a script URL carrying https: later in the string is still not an http(s) target',
            ],
            'data href' => [
                '<a href="data:text/html,pwned">read more</a>',
                'read more',
                'a data URL loses the anchor and keeps the text',
            ],
            'nested tags' => [
                '<b>Bold</b> and <span style="x">span</span>',
                'Bold and span',
                'every non-anchor tag is dropped and its text kept',
            ],
            'unclosed anchor' => [
                '<a href="' . self::URL . '">read more',
                '<a href="' . self::URL . '">read more</a>',
                'an anchor left open is closed rather than swallowing the page',
            ],
            'unterminated tag' => [
                'read <a href="' . self::URL . '"',
                'read &lt;a href=&quot;' . self::URL . '&quot;',
                'a tag with no closing bracket is text, not markup',
            ],
            'script element' => [
                '<script>alert(1)</script>',
                'alert(1)',
                'a script element is reduced to inert text',
            ],
            'anchor inside anchor' => [
                '<a href="https://a.example.test/1">outer <a href="https://b.example.test/2">inner</a> tail</a>',
                '<a href="https://a.example.test/1">outer inner</a> tail',
                'a nested anchor loses its tag, not its text',
            ],
            'http scheme' => [
                '<a href="http://faq.example.test/x">read more</a>',
                '<a href="http://faq.example.test/x">read more</a>',
                'plain http is a reachable page, not only https',
            ],
            'uppercase tag' => [
                '<A HREF="' . self::URL . '">read more</A>',
                '<a href="' . self::URL . '">read more</a>',
                'an uppercase tag is markup too, not text',
            ],
            'padded href' => [
                '<a href="  ' . self::URL . '  ">read more</a>',
                '<a href="' . self::URL . '">read more</a>',
                'padding a stored href does not change the target',
            ],
            'entity-encoded script URL' => [
                '<a href="&#106;avascript:alert(1)">read more</a>',
                'read more',
                'entity-encoding a script URL does not smuggle it past the scheme test',
            ],
            'entity already in the copy' => [
                'Tea &amp; coffee & cake',
                'Tea &amp; coffee &amp; cake',
                'an entity already in the copy is left alone while a bare ampersand is escaped',
            ],
            'two-parameter query string' => [
                '<a href="https://faq.example.test/x?a=1&amp;b=2">read more</a>',
                '<a href="https://faq.example.test/x?a=1&amp;b=2">read more</a>',
                'a two-parameter query string survives one decode and one re-encode unchanged',
            ],
            'quote inside href' => [
                "<a href='https://faq.example.test/x?q=\"z\"'>read more</a>",
                '<a href="https://faq.example.test/x?q=&quot;z&quot;">read more</a>',
                'a quote inside the href is encoded rather than closing the attribute',
            ],
            'empty double-quoted href' => [
                '<a href="">read more</a>',
                'read more',
                'an empty href is no link',
            ],
            'empty single-quoted href' => [
                "<a href=''>read more</a>",
                'read more',
                'nor is an empty single-quoted one',
            ],
            'userinfo in href' => [
                '<a href="https://user:pw@evil.example.test">read more</a>',
                'read more',
                'userinfo lets the text before the @ pose as the host, so the link is dropped',
            ],
            'at sign past the authority' => [
                '<a href="https://faq.example.test/x?to=a@b">read more</a>',
                '<a href="https://faq.example.test/x?to=a@b">read more</a>',
                'an @ past the authority is ordinary query text',
            ],
            'at sign in a query on the authority itself' => [
                '<a href="https://faq.example.test?to=a@b">read more</a>',
                '<a href="https://faq.example.test?to=a@b">read more</a>',
                'a query opening straight off the authority ends it, so the @ after it is not userinfo',
            ],
            'at sign in a fragment on the authority itself' => [
                '<a href="https://faq.example.test#@b">read more</a>',
                '<a href="https://faq.example.test#@b">read more</a>',
                'a fragment ends the authority the same way',
            ],
            'uppercase target and rel' => [
                '<a href="' . self::URL . '" target="_BLANK" rel="NOOPENER">read more</a>',
                '<a href="' . self::URL . '" target="_blank" rel="noopener">read more</a>',
                'browsers read these keywords case-insensitively, so they are matched that way and re-emitted lowercased',
            ],
            'target without rel' => [
                '<a href="' . self::URL . '" target="_blank">read more</a>',
                '<a href="' . self::URL . '" target="_blank" rel="noopener">read more</a>',
                'a new-tab link gets noopener whether or not the copy asked for it',
            ],
            'stricter rel token set' => [
                '<a href="' . self::URL . '" rel="noopener noreferrer">read more</a>',
                '<a href="' . self::URL . '" rel="noopener">read more</a>',
                'rel is read as a token set, so writing the stricter pair does not cost the link its noopener',
            ],
            'uppercase rel on its own' => [
                '<a href="' . self::URL . '" rel="NOOPENER">read more</a>',
                '<a href="' . self::URL . '" rel="noopener">read more</a>',
                'rel is matched case-insensitively even with no target to pair it with',
            ],
            'malformed utf-8 byte' => [
                "caf\xC3\xA9 \xC0\xAF costs \xE2\x82\xAC5",
                "caf\u{00E9} \u{FFFD}\u{FFFD} costs \u{20AC}5",
                'one malformed byte is substituted, not allowed to blank the whole run',
            ],
            'control character' => [
                "safe\x00ish",
                'safeish',
                'a control character cannot render and is dropped',
            ],
            'stray less-than' => [
                'Pay in 30 days < see <a href="' . self::URL . '">terms</a>',
                'Pay in 30 days &lt; see <a href="' . self::URL . '">terms</a>',
                'a stray < is text and does not swallow the copy up to the next >',
            ],
            'uppercase scheme' => [
                '<a href="HTTPS://x.example.test">read more</a>',
                '<a href="HTTPS://x.example.test">read more</a>',
                'browsers read the scheme case-insensitively, so an uppercase one is still a link',
            ],
            'padded close tag' => [
                '<a href="' . self::URL . '">read more</a  > and on',
                '<a href="' . self::URL . '">read more</a> and on',
                'padding inside the close tag still closes the anchor rather than letting it swallow the tail',
            ],
            'repeated href' => [
                '<a href="javascript:alert(1)" href="' . self::URL . '">read more</a>',
                'read more',
                'browsers act on the first attribute, so a later href cannot launder the script URL in front of it',
            ],
            'repeated rel' => [
                '<a href="' . self::URL . '" rel="nofollow" rel="noopener">read more</a>',
                '<a href="' . self::URL . '">read more</a>',
                'the first rel is the one that counts, so a later noopener is not read as one',
            ],
            'anchor-prefixed tag name' => [
                '<abbr href="' . self::URL . '">read more</abbr>',
                'read more',
                'only the anchor element is an anchor, not every tag whose name starts with one',
            ],
        ];
    }

    /**
     * @return array<string, array{0:string,1:string,2:string}>
     */
    public static function textOnlyRows(): array
    {
        return [
            'an anchor' => [
                '<a href="https://evil.test" target="_blank">click</a>',
                '&lt;a href=&quot;https://evil.test&quot; target=&quot;_blank&quot;&gt;click&lt;/a&gt;',
                'text-only copy has no link to preserve, so even a valid anchor stays visible text',
            ],
            'an event-handler tag' => [
                '<img src=x onerror=alert(1)>',
                '&lt;img src=x onerror=alert(1)&gt;',
                'the classic payload renders as its own source',
            ],
            'copy already carrying entities' => [
                '&lt;script&gt;alert(1)&lt;/script&gt;',
                '&lt;script&gt;alert(1)&lt;/script&gt;',
                'an entity is already inert, and re-encoding it would show the buyer the entity itself',
            ],
            'a numeric character reference' => [
                '&#60;img src=x onerror=alert(1)&#62;',
                '&#60;img src=x onerror=alert(1)&#62;',
                'innerHTML decodes this in the data state, so it is text rather than a tag',
            ],
            'a control character' => [
                "<\x00img src=x>",
                '&lt;img src=x&gt;',
                'a NUL cannot be used to break the escape up',
            ],
            'plain copy' => [
                'Pay later & relax',
                'Pay later &amp; relax',
                'ordinary copy survives with only its ampersand encoded',
            ],
        ];
    }

    /**
     * @dataProvider textOnlyRows
     */
    public function testTextOnlyEscapingKeepsNoMarkup(string $input, string $expected, string $description): void
    {
        $this->assertSame($expected, (new AnchorOnlyHtmlEscaper())->escapeTextOnly($input), $description);
    }

    /**
     * @dataProvider escapingRows
     */
    public function testEscaping(string $input, string $expected, string $description): void
    {
        $this->assertSame($expected, (new AnchorOnlyHtmlEscaper())->escape($input), $description);
    }

    /**
     * The subtitle is re-escaped on every render, so a second pass has to be a
     * no-op - otherwise each render would re-encode the last one's entities.
     *
     * @dataProvider escapingRows
     */
    public function testEscapingIsIdempotent(string $input, string $expected, string $description): void
    {
        $this->assertSame($expected, (new AnchorOnlyHtmlEscaper())->escape($expected), 'escaping twice changes the output: ' . $description);
    }

    /**
     * An empty quoted value leaves its capture group absent, and the resulting
     * notice would be written into the middle of the checkout markup on a shop
     * with display_errors on.
     */
    public function testAnEmptyAttributeValueRaisesNoWarning(): void
    {
        $raised = [];
        set_error_handler(static function (int $severity, string $message) use (&$raised): bool {
            $raised[] = $message;

            return true;
        });
        try {
            (new AnchorOnlyHtmlEscaper())->escape('<a href="" target="" rel="">read more</a>');
        } finally {
            restore_error_handler();
        }

        $this->assertSame([], $raised, 'escaping an empty attribute value raised: ' . implode('; ', $raised));
    }

    /**
     * @return array<string, array{0:string,1:bool,2:string}>
     */
    public static function renderBoundaryRows(): array
    {
        return [
            'empty' => ['', true, 'an empty subtitle is nothing to strip'],
            'plain copy' => ['Pay later, interest free', true, 'plain copy renders verbatim'],
            'apostrophe and ampersand' => [
                "Don't wait & save",
                true,
                'text the escaper only entity-encodes is not markup',
            ],
            'stray less-than' => ['2 < 3', true, 'a stray < is encoded as text, not treated as markup'],
            'pre-existing entity' => ['Tea &amp; coffee', true, 'copy that already carries an entity is left alone'],
            'permitted anchor' => [
                '<a href="' . self::URL . '">read more</a>',
                true,
                'the anchor the tile allows survives verbatim',
            ],
            'permitted new-tab anchor' => [
                '<a href="' . self::URL . '" target="_blank" rel="noopener">read more</a>',
                true,
                'so does the new-tab form this module itself emits',
            ],
            'dropped tag' => ['<b>Pay</b> later', false, 'a dropped tag changes what the buyer reads'],
            'refused href' => [
                '<a href="javascript:alert(1)">read more</a>',
                false,
                'a link whose target is refused loses its anchor',
            ],
            'anchor rewritten to add noopener' => [
                '<a href="' . self::URL . '" target="_blank">read more</a>',
                false,
                'a new-tab link is rewritten to carry noopener, so it is not what was typed',
            ],
            'dropped attribute' => [
                '<a href="' . self::URL . '" onclick="steal()">read more</a>',
                false,
                'a dropped attribute is a change too',
            ],
            'control character' => ["safe\x00ish", false, 'a control character is removed'],
        ];
    }

    /**
     * The admin accept/reject boundary is this escaper's own render boundary:
     * entity-encoding plain text is not a change.
     *
     * @dataProvider renderBoundaryRows
     */
    public function testRendersUnchangedIgnoresEntityEncodingOnly(string $input, bool $expected, string $description): void
    {
        $this->assertSame($expected, (new AnchorOnlyHtmlEscaper())->rendersUnchanged($input), $description);
    }

    /** A non-string subtitle yields '' rather than a TypeError, as on the other platforms. */
    public function testANonStringInputIsCoerced(): void
    {
        $this->assertSame('', (new AnchorOnlyHtmlEscaper())->escape(null));
    }
}
