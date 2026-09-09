<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Model\Config;

use PHPUnit\Framework\TestCase;
use Two\Gateway\Model\Config\StoredTerm;

/**
 * The single reading of a stored custom payment term (ABN-522). Test/Unit/Config/CustomTermParityTest
 * pins each consumer to it.
 */
class StoredTermTest extends TestCase
{
    /**
     * @param mixed $stored
     * @dataProvider shapeProvider
     */
    public function testShape($stored, bool $blank, ?int $days, bool $unusable, string $case): void
    {
        $this->assertSame($blank, StoredTerm::isBlank($stored), "$case — blank");
        $this->assertSame($days, StoredTerm::days($stored), "$case — days");
        $this->assertSame($unusable, StoredTerm::isUnusable($stored), "$case — unusable");
    }

    public static function shapeProvider(): array
    {
        return [
            ['', true, null, false, 'nothing stored'],
            ['   ', true, null, false, 'whitespace is nothing stored'],
            [null, true, null, false, 'no row at this scope'],
            [[], true, null, false, 'an array is not a value'],
            ['0', true, null, false, 'a zero is blank, not a term'],
            ['000', true, null, false, 'any run of zeros is blank'],
            ['  0  ', true, null, false, 'a padded zero is blank'],
            ['30', false, 30, false, 'a plain term'],
            [30, false, 30, false, 'an int reads the same as its string'],
            ['030', false, 30, false, 'leading zeros normalise to the same term'],
            ['  30  ', false, 30, false, 'padding is trimmed'],
            ['-5', false, null, true, 'a negative is unusable, not blank'],
            ['30.0', false, null, true, 'a decimal is unusable even where it names a whole number'],
            ['1e2', false, null, true, 'exponent notation is unusable'],
            ['abc', false, null, true, 'non-numeric junk is unusable'],
            ['30abc', false, null, true, 'a numeric prefix does not make it a term'],
        ];
    }
}
