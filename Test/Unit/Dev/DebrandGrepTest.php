<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Dev;

use PHPUnit\Framework\TestCase;

/**
 * `dev/debrand-grep.sh` is the only thing standing between a hardcoded brand
 * name and a partner's storefront, and every way it has been found disarmed so
 * far was silent — it went on reporting OK over a planted leak. Each case below
 * plants one file in an otherwise empty tree and reads the gate's own report.
 */
class DebrandGrepTest extends TestCase
{
    /**
     * @dataProvider surfaces
     */
    public function testTheGateReportsExactlyTheLeaksItIsAimedAt(
        string $fixture,
        string $path,
        ?string $expectedReport,
        string $description
    ): void {
        [$status, $output] = $this->runGate($fixture, $path);

        if ($expectedReport === null) {
            $this->assertSame(0, $status, $description . " — gate said:\n" . $output);
            $this->assertStringContainsString('debrand-grep OK', $output, $description);
            return;
        }

        $this->assertSame(1, $status, $description . " — gate said:\n" . $output);
        $this->assertStringContainsString($expectedReport, $output, $description);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string|null, 3: string}>
     */
    public static function surfaces(): array
    {
        $template = 'view/frontend/web/template/payment/probe.html';
        $phtml = 'view/frontend/templates/probe.phtml';
        $layout = 'view/frontend/layout/probe.xml';
        $adminJs = 'view/adminhtml/web/js/probe.js';
        $php = 'Model/Probe.php';

        return [
            'knockout virtual element' => [
                'ko-virtual-element.fixture',
                $template,
                $template . ':1:',
                'a `<!-- ko i18n: … -->` binding renders, so it is not a comment to skip',
            ],
            'plain markup comment' => [
                'plain-markup-comment.fixture',
                $template,
                null,
                'an ordinary markup comment reaches no buyer',
            ],
            'jQuery translation call' => [
                'mage-translate-call.fixture',
                $adminJs,
                $adminJs . ':2:',
                '$.mage.__() is the admin JS translation idiom',
            ],
            'injected translator call' => [
                'injected-translator-call.fixture',
                'view/frontend/web/js/model/probe.js',
                'view/frontend/web/js/model/probe.js:1:',
                'the vendored company-search modules translate through an injected function',
            ],
            'Luma translation call' => [
                'dollar-t-call.fixture',
                'view/frontend/web/js/probe.js',
                'view/frontend/web/js/probe.js:2:',
                '$t() is the checkout JS translation idiom',
            ],
            'heredoc holding an apostrophe' => [
                'php-heredoc-apostrophe.fixture',
                $php,
                $php . ':5:',
                'an apostrophe inside a heredoc must not open a string and swallow the rest of the file',
            ],
            'regex literal holding an apostrophe' => [
                'js-regex-apostrophe.fixture',
                'view/frontend/web/js/probe.js',
                'view/frontend/web/js/probe.js:2:',
                'an apostrophe inside a regex literal must not open a string',
            ],
            'bracket inside a translated literal' => [
                'js-bracket-in-a-string.fixture',
                'view/frontend/web/js/probe.js',
                null,
                'parens are balanced in a copy with the strings blanked, so a quoted one closes nothing',
            ],
            'postfix increment before a division' => [
                'js-postfix-increment-division.fixture',
                'view/frontend/web/js/probe.js',
                'view/frontend/web/js/probe.js:2:',
                'a `/` after `i++` divides, so it must not open a regex and blank the msgid behind it',
            ],
            'comment holding an apostrophe, outside the call' => [
                'php-comment-apostrophe-outside-call.fixture',
                $php,
                $php . ':3:',
                'an apostrophe in a preceding comment must not open a string',
            ],
            'comment holding an apostrophe, inside the call' => [
                'php-comment-apostrophe-inside-call.fixture',
                $php,
                $php . ':4:',
                'an apostrophe in a comment between the parens must not open a string',
            ],
            'legal entity inside a translated string' => [
                'php-two-inc-in-call.fixture',
                $php,
                $php . ':2:',
                'the legal entity carries the brand, so a msgid naming it is a leak',
            ],
            'copyright header in a template' => [
                'phtml-copyright-header.fixture',
                $phtml,
                null,
                'the legal entity in a header names the licensor, not the brand',
            ],
            'legal entity in live markup' => [
                'phtml-legal-entity-live.fixture',
                $phtml,
                $phtml . ':1:',
                'the legal entity carries the brand into markup no overlay can rewrite',
            ],
            'PHP comment in a template' => [
                'phtml-php-comment.fixture',
                $phtml,
                null,
                'a template must be annotatable in the language it is written in',
            ],
            'live brand name in a template' => [
                'phtml-live-brand.fixture',
                $phtml,
                $phtml . ':1:',
                'a template line no overlay can rewrite is the surface this gate exists for',
            ],
            'escape sequence in a template PHP string' => [
                'phtml-escaped-brand.fixture',
                $phtml,
                $phtml . ':1:',
                'the FQCN carve-out exempts a namespace separator, not an escape',
            ],
            'concatenated msgid over several lines' => [
                'js-multiline-concatenated-call.fixture',
                'view/frontend/web/js/probe.js',
                'view/frontend/web/js/probe.js:4: with Two',
                'a concatenated msgid is read whole, so a brand on its second piece is reported',
            ],
            'brand name in a markup comment' => [
                'xml-brand-in-comment.fixture',
                $layout,
                null,
                'a markup comment reaches no buyer',
            ],
            'vanilla admin form' => [
                'xml-brand-live.fixture',
                'etc/adminhtml/system.xml',
                null,
                'system.xml is the vanilla form an overlay replaces wholesale',
            ],
            'sibling admin XML' => [
                'xml-brand-live.fixture',
                'etc/adminhtml/probe.xml',
                'etc/adminhtml/probe.xml:2:',
                'every other adminhtml XML is synthesised into the brand form',
            ],
            'database schema' => [
                'xml-brand-live.fixture',
                'etc/db_schema.xml',
                null,
                'schema comments reach no user',
            ],
            'unit test' => [
                'php-brand-in-call.fixture',
                'Test/Unit/Probe.php',
                null,
                'a unit test asserts the vanilla brand own strings',
            ],
            'e2e suite' => [
                'php-brand-in-call.fixture',
                'e2e/Probe.php',
                null,
                'an e2e suite asserts the vanilla brand own strings',
            ],
            'production PHP' => [
                'php-brand-in-call.fixture',
                $php,
                $php . ':2:',
                'a msgid carries the brand in every locale, English included',
            ],
            'plain PHP literal' => [
                'php-brand-in-plain-literal.fixture',
                $php,
                $php . ':2:',
                'a literal reaches a logger or an exception without passing through __()',
            ],
            'FQCN in a plain literal' => [
                'php-fqcn-in-plain-literal.fixture',
                $php,
                null,
                'a class-string names a real class, escaped or not',
            ],
            'copyright header in non-test PHP' => [
                'php-copyright-header.fixture',
                $php,
                null,
                'a header is a comment, and comments are blanked before the literal walk',
            ],
            'escape sequence after the brand' => [
                'php-escaped-brand-in-plain-literal.fixture',
                $php,
                $php . ':2:',
                'a `\\n` after the brand escapes a newline, it does not open a namespace',
            ],
            'escaped apostrophe after the brand' => [
                'php-escaped-apostrophe-in-plain-literal.fixture',
                $php,
                $php . ':2:',
                'a `\\\'` after the brand escapes a quote, it does not open a namespace',
            ],
            'brand on a later line of a heredoc' => [
                'php-heredoc-brand-line.fixture',
                $php,
                $php . ':5: Two rejected this order',
                'a heredoc is one literal over many lines, so the report names the brand line, not the `<<<`',
            ],
            'two brand lines in one heredoc' => [
                'php-heredoc-two-brand-lines.fixture',
                $php,
                $php . ":3: Two rejected this order\n  " . $php . ':5: Two will retry tomorrow',
                'one CI round per leak, not one per mention',
            ],
        ];
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function runGate(string $fixture, string $path): array
    {
        $repo = dirname(__DIR__, 3);
        $root = sys_get_temp_dir() . '/debrand-grep-' . bin2hex(random_bytes(8));

        mkdir($root . '/dev', 0777, true);
        copy($repo . '/dev/debrand-grep.sh', $root . '/dev/debrand-grep.sh');
        mkdir(dirname($root . '/' . $path), 0777, true);
        copy($repo . '/Test/Unit/Dev/_files/debrand-grep/' . $fixture, $root . '/' . $path);

        $output = [];
        exec('bash ' . escapeshellarg($root . '/dev/debrand-grep.sh') . ' 2>&1', $output, $status);
        self::removeTree($root);

        return [$status, implode("\n", $output)];
    }

    private static function removeTree(string $path): void
    {
        foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
            $child = $path . '/' . $entry;
            if (is_dir($child)) {
                self::removeTree($child);
            } else {
                unlink($child);
            }
        }
        rmdir($path);
    }
}
