<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\I18n;

use PHPUnit\Framework\TestCase;

/**
 * Magento builds the storefront's JS dictionary by scanning for literal `$t()`
 * calls, so a phrase the picker only ever asks its host for by name renders in
 * English with no error and no log line (ABN-555).
 */
class SharedCapturePhraseHarvestTest extends TestCase
{
    private const LOCALES = ['nb_NO', 'nl_NL', 'sv_SE'];

    /** Modules that ask for a phrase by name rather than translating it. */
    private const SEAM_MODULES = [
        'view/frontend/web/js/model/company-capture-component.js',
        'view/frontend/web/js/model/company-search-panel.js',
    ];

    /** The only file in that chain Magento's scanner can harvest a phrase from. */
    private const HOST_MODULE = 'view/frontend/web/js/model/company-capture.js';

    /** Guards the extraction below against silently matching nothing. */
    private const KNOWN_SEAM_PHRASE_COUNT = 7;

    public function testTheHostAnswersTheSeamFromThatDictionary(): void
    {
        $this->assertStringContainsString(
            'translate: translateSharedPhrase',
            $this->source(self::HOST_MODULE),
            'The host spells the phrases out but hands the seam a translator that bypasses them,'
            . ' so the dictionary is dead code and nothing keeps it in step with the seam.'
        );
    }

    public function testEverySeamPhraseIsHarvestableByMagentosScanner(): void
    {
        $harvestable = $this->literals($this->source(self::HOST_MODULE), '\$t');

        $unharvestable = array_values(array_diff($this->seamPhrases(), $harvestable));

        $this->assertSame(
            [],
            $unharvestable,
            sprintf(
                "%d picker phrase(s) never reach the storefront JS dictionary and so render in"
                . " English — spell each out as a \$t() call in %s:\n  %s",
                count($unharvestable),
                self::HOST_MODULE,
                implode("\n  ", $unharvestable)
            )
        );
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function localeProvider(): array
    {
        $cases = [];
        foreach (self::LOCALES as $locale) {
            $cases[$locale] = [$locale];
        }

        return $cases;
    }

    /**
     * @dataProvider localeProvider
     */
    public function testEverySeamPhraseHasATranslation(string $locale): void
    {
        $rows = $this->catalogue($locale);

        $missing = [];
        foreach ($this->seamPhrases() as $phrase) {
            if (!isset($rows[$phrase]) || trim($rows[$phrase]) === '') {
                $missing[] = $phrase;
            }
        }

        $this->assertSame(
            [],
            $missing,
            sprintf(
                "%d picker phrase(s) render in English for %s — add a row to i18n/%s.csv:\n  %s",
                count($missing),
                $locale,
                $locale,
                implode("\n  ", $missing)
            )
        );
    }

    /**
     * @return list<string>
     */
    private function seamPhrases(): array
    {
        $phrases = [];
        foreach (self::SEAM_MODULES as $relative) {
            $phrases = array_merge($phrases, $this->literals($this->source($relative), '(?:this|self)\.translate'));
        }

        $phrases = array_values(array_unique($phrases));

        $this->assertGreaterThanOrEqual(
            self::KNOWN_SEAM_PHRASE_COUNT,
            count($phrases),
            sprintf('Found only %d phrase(s) behind the translate seam — the extraction is broken.', count($phrases))
        );

        return $phrases;
    }

    /**
     * String literals passed to a named call, both quote styles.
     *
     * @param string $callee regex matching the callee
     * @return list<string>
     */
    private function literals(string $source, string $callee): array
    {
        $found = [];
        foreach (["'", '"'] as $quote) {
            $pattern = sprintf(
                '/%s\(\s*%s((?:[^%s\\\\]|\\\\.)*)%s/',
                $callee,
                $quote,
                $quote,
                $quote
            );
            if (preg_match_all($pattern, $source, $matches)) {
                foreach ($matches[1] as $literal) {
                    $found[] = str_replace(['\\' . $quote, '\\\\'], [$quote, '\\'], $literal);
                }
            }
        }

        return array_values(array_unique($found));
    }

    private function source(string $relative): string
    {
        $path = dirname(__DIR__, 3) . '/' . $relative;
        $this->assertFileExists($path, sprintf('Cannot read %s.', $relative));

        return (string) file_get_contents($path);
    }

    /**
     * @return array<string, string> msgid => translation
     */
    private function catalogue(string $locale): array
    {
        $path = dirname(__DIR__, 3) . '/i18n/' . $locale . '.csv';
        $handle = fopen($path, 'r');
        $this->assertNotFalse($handle, sprintf('Cannot read i18n/%s.csv.', $locale));

        $rows = [];
        while (($row = fgetcsv($handle, null, ',', '"', '')) !== false) {
            if (isset($row[0], $row[1])) {
                $rows[$row[0]] = (string) $row[1];
            }
        }
        fclose($handle);

        $this->assertGreaterThan(
            100,
            count($rows),
            sprintf('Parsed only %d rows from i18n/%s.csv — the parse is broken.', count($rows), $locale)
        );

        return $rows;
    }
}
