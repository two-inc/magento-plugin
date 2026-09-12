<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\I18n;

use PHPUnit\Framework\TestCase;

/**
 * The buyer meets the "no company number" refusal in a language, not in
 * English. An untranslated msgid renders the English source with no error,
 * no warning and no log line, so nothing but a check like this notices.
 */
class CompanyGateMessageTranslationTest extends TestCase
{
    private const CLIENT_MSGID = 'Please select your company before paying with %1.';

    private const SERVER_MSGID = 'Invoice purchase with %1 requires a company number.'
        . ' Please add your company details and try again.';

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function companyGateMessageProvider(): array
    {
        $cases = [];

        foreach (['nb_NO', 'nl_NL', 'sv_SE'] as $locale) {
            $cases[$locale . ' submit block'] = [
                $locale,
                self::CLIENT_MSGID,
                'the checkout submit block',
            ];
            $cases[$locale . ' server refusal'] = [
                $locale,
                self::SERVER_MSGID,
                'the server-side refusal',
            ];
        }

        return $cases;
    }

    /**
     * @dataProvider companyGateMessageProvider
     */
    public function testCompanyGateMessageIsTranslated(
        string $locale,
        string $msgid,
        string $description
    ): void {
        $rows = $this->loadCatalogue($locale);

        $this->assertArrayHasKey(
            $msgid,
            $rows,
            sprintf('%s renders in English for %s: no i18n/%s.csv row.', $description, $locale, $locale)
        );
        $this->assertNotSame(
            '',
            trim($rows[$msgid]),
            sprintf('%s has an empty %s translation.', $description, $locale)
        );
        $this->assertStringContainsString(
            '%1',
            $rows[$msgid],
            sprintf('%s drops the brand placeholder in %s.', $description, $locale)
        );
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

        // A parse that yields almost nothing would make every assertion above
        // vacuously pass on a missing row.
        $this->assertGreaterThan(
            100,
            count($rows),
            sprintf('Parsed only %d rows from i18n/%s.csv — the parse is broken.', count($rows), $locale)
        );

        return $rows;
    }
}
