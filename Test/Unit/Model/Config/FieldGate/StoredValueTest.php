<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Model\Config\FieldGate;

use PHPUnit\Framework\TestCase;
use Two\Gateway\Model\Config\FieldGate\StoredValue;

/**
 * The gate on the deprecated "Custom payment terms (days)": it renders for exactly the
 * merchants who already carry a value, and for nobody else (ABN-522).
 */
class StoredValueTest extends TestCase
{
    /**
     * @param mixed $stored
     * @dataProvider storedProvider
     */
    public function testIsConfigured($stored, bool $expected, string $case): void
    {
        $this->assertSame($expected, (new StoredValue())->isConfigured($stored), $case);
    }

    public static function storedProvider(): array
    {
        return [
            ['30', true, 'a legacy custom term'],
            ['0', true, 'a stored zero is still a stored value the merchant can remove'],
            [30, true, 'an int reads the same as its string'],
            ['', false, 'nothing stored'],
            ['   ', false, 'whitespace is nothing stored'],
            [null, false, 'no row at this scope'],
            [[], false, 'an array is not a day count'],
        ];
    }
}
