<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\I18n;

use PHPUnit\Framework\TestCase;

/**
 * An admin label with no catalogue row renders in English with no error, no
 * warning and no log line, and a Title Case one reads as a different msgid
 * from the sentence-case row that carries its translation — so a copy edit
 * silently orphans the human translation it was meant to keep.
 */
class AdminFormCatalogueTest extends TestCase
{
    private const LOCALES = ['nb_NO', 'nl_NL', 'sv_SE'];

    private const ADMIN_FORMS = [
        'etc/adminhtml/brand_form_template.xml',
        'etc/adminhtml/system.xml',
    ];

    /**
     * Capitalised mid-caption words that are names or initialisms, not Title Case.
     */
    private const PROPER_NOUNS = [
        'Two', 'Magento', 'Hyva', 'SSL', 'API', 'CDN', 'IP', 'CIDR',
        'VAT', 'PO', 'ID', 'URL', 'On', 'Off',
    ];

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
    public function testEveryAdminCaptionHasATranslation(string $locale): void
    {
        $msgids = $this->adminMsgids();
        $rows = $this->loadCatalogue($locale);

        $missing = [];
        foreach ($msgids as $msgid) {
            if (!isset($rows[$msgid]) || trim($rows[$msgid]) === '') {
                $missing[] = $msgid;
            }
        }

        $this->assertSame(
            [],
            $missing,
            sprintf(
                "%d admin caption(s) render in English for %s — add a row to i18n/%s.csv:\n  %s",
                count($missing),
                $locale,
                $locale,
                implode("\n  ", $missing)
            )
        );
    }

    /**
     * Admin captions are Sentence case, never Title Case.
     */
    public function testAdminLabelsAreSentenceCase(): void
    {
        $offenders = [];
        foreach (self::ADMIN_FORMS as $relative) {
            foreach ($this->elementTexts($relative, 'label') as $label) {
                // A quoted phrase inside a caption keeps its own capital.
                foreach (array_slice(preg_split('/\s+/', $label), 1) as $word) {
                    $bare = trim($word, '(),.:;%');
                    if (preg_match('/^[A-Z][a-z]+$/', $bare)
                        && !in_array($bare, self::PROPER_NOUNS, true)
                    ) {
                        $offenders[] = sprintf('%s: "%s" (%s)', $relative, $label, $bare);
                        break;
                    }
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            sprintf("Title Case admin caption(s):\n  %s", implode("\n  ", $offenders))
        );
    }

    /**
     * Labels and comments from the admin form definitions.
     *
     * @return array<int, string>
     */
    private function adminMsgids(): array
    {
        $msgids = [];
        foreach (self::ADMIN_FORMS as $relative) {
            foreach (['label', 'comment'] as $tag) {
                foreach ($this->elementTexts($relative, $tag) as $text) {
                    // A caption still carrying a brand token is a template:
                    // the substituted result is what reaches the catalogue.
                    if (strpos($text, '{{') !== false) {
                        continue;
                    }
                    // The brand name is a name, not translatable copy.
                    if ($text === 'Two') {
                        continue;
                    }
                    $msgids[$text] = true;
                }
            }
        }

        $msgids = array_keys($msgids);

        // A parse that yielded almost nothing would make the coverage
        // assertion vacuously pass.
        $this->assertGreaterThan(
            80,
            count($msgids),
            sprintf('Parsed only %d admin captions — the parse is broken.', count($msgids))
        );

        return $msgids;
    }

    /**
     * @return array<int, string>
     */
    private function elementTexts(string $relative, string $tag): array
    {
        $path = dirname(__DIR__, 3) . '/' . $relative;
        $xml = simplexml_load_file($path);
        $this->assertNotFalse($xml, sprintf('Cannot parse %s.', $relative));

        $texts = [];
        foreach ($xml->xpath('//' . $tag) ?: [] as $node) {
            $text = trim((string) $node);
            if ($text !== '') {
                $texts[] = $text;
            }
        }

        return $texts;
    }

    /**
     * @return array<string, string> msgid => translation
     */
    private function loadCatalogue(string $locale): array
    {
        $path = dirname(__DIR__, 3) . '/i18n/' . $locale . '.csv';
        $handle = fopen($path, 'r');
        $this->assertNotFalse($handle, sprintf('Cannot read i18n/%s.csv.', $locale));

        $rows = [];
        while (($row = fgetcsv($handle)) !== false) {
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
