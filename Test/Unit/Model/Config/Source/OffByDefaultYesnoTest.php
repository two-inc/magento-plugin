<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Model\Config\Source;

use PHPUnit\Framework\TestCase;
use Two\Gateway\Model\Config\Source\OffByDefaultYesno;

/**
 * TWO-25800: an opt-in must READ as off on a brand that declares no default.
 *
 * Magento renders a select with no stored value as its first option, and this
 * package can declare a default only under its own payment code — an overlay
 * brand's field resolves payment/<its code>/..., for which nothing is declared.
 * With No first, the form agrees with the read path on every brand.
 */
class OffByDefaultYesnoTest extends TestCase
{
    public function testNoIsOfferedFirstSoAnUndeclaredDefaultDisplaysAsOff(): void
    {
        $options = (new OffByDefaultYesno())->toOptionArray();

        $this->assertSame(0, $options[0]['value']);
        $this->assertSame(1, $options[1]['value']);
    }

    /** Core's own stored values, so nothing downstream changes. */
    public function testTheStoredValuesAreCoreYesno(): void
    {
        $values = array_column((new OffByDefaultYesno())->toOptionArray(), 'value');

        $this->assertSame([0, 1], $values);
    }
}
