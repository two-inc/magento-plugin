<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Model\Config\Backend;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\Context;
use Magento\Framework\Registry;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Model\Config\Backend\CustomHeaders;

/**
 * The entry gate for the custom-header table: what the admin can store, and
 * the round trip back into the grid.
 */
class CustomHeadersTest extends TestCase
{
    /**
     * @param mixed $value
     */
    private function backend($value): CustomHeaders
    {
        return new CustomHeaders(
            $this->getMockBuilder(Context::class)->disableOriginalConstructor()->getMock(),
            $this->getMockBuilder(Registry::class)->disableOriginalConstructor()->getMock(),
            $this->createMock(ScopeConfigInterface::class),
            $this->createMock(TypeListInterface::class),
            null,
            null,
            ['value' => $value, 'scope' => 'default', 'scope_id' => 0]
        );
    }

    /**
     * @param array<mixed> $posted
     */
    private function save(array $posted): string
    {
        $backend = $this->backend($posted);
        $backend->beforeSave();

        return (string)$backend->getValue();
    }

    /**
     * Given rows as the grid posts them; When saved; Then they are stored
     * re-keyed and normalised.
     *
     * @dataProvider acceptedRows
     *
     * @param array<mixed> $posted
     * @param array<string, mixed> $expected
     */
    public function testAcceptedRowsAreStoredNormalised(
        array $posted,
        array $expected,
        string $description
    ): void {
        $stored = $this->save($posted);

        $this->assertSame($expected, $stored === '' ? [] : json_decode($stored, true), $description);
    }

    /**
     * @return array<string, array{0: array<mixed>, 1: array<string, mixed>, 2: string}>
     */
    public static function acceptedRows(): array
    {
        return [
            'one ticked row' => [
                ['_1725' => ['name' => 'X-WAF-TOKEN', 'value' => 'abc', 'send_from_browser' => '1']],
                ['_1' => ['name' => 'X-WAF-TOKEN', 'value' => 'abc', 'send_from_browser' => '1']],
                'a ticked row keeps its flag',
            ],
            'unticked row posts no flag at all' => [
                ['_1725' => ['name' => 'X-WAF-TOKEN', 'value' => 'abc']],
                ['_1' => ['name' => 'X-WAF-TOKEN', 'value' => 'abc', 'send_from_browser' => '']],
                'an unticked checkbox posts nothing, which reads as off',
            ],
            'timestamp keys are replaced' => [
                [
                    '_1725000001' => ['name' => 'X-One', 'value' => '1'],
                    '_1725000002' => ['name' => 'X-Two', 'value' => '2'],
                ],
                [
                    '_1' => ['name' => 'X-One', 'value' => '1', 'send_from_browser' => ''],
                    '_2' => ['name' => 'X-Two', 'value' => '2', 'send_from_browser' => ''],
                ],
                'stored keys are positional, so an unchanged table stores an unchanged value',
            ],
            'surrounding whitespace is trimmed' => [
                ['_1' => ['name' => '  X-WAF-TOKEN ', 'value' => " abc\n"]],
                ['_1' => ['name' => 'X-WAF-TOKEN', 'value' => 'abc', 'send_from_browser' => '']],
                'a pasted value carries whitespace a header cannot',
            ],
            'the grid always posts its empty marker' => [
                ['__empty' => ''],
                [],
                'no rows stores nothing at all',
            ],
            'a wholly blank row is dropped' => [
                ['_1' => ['name' => '', 'value' => ''], '__empty' => ''],
                [],
                'an added-then-abandoned row is not an error',
            ],
        ];
    }

    /**
     * Given a row that could not be sent; When saved; Then the save is
     * refused naming the row, rather than storing something inert.
     *
     * @dataProvider refusedRows
     *
     * @param array<mixed> $posted
     */
    public function testAnUnsendableRowIsRefused(array $posted, string $expectedMessage): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage($expectedMessage);

        $this->save($posted);
    }

    /**
     * @return array<string, array{0: array<mixed>, 1: string}>
     */
    public static function refusedRows(): array
    {
        return [
            'no name' => [
                ['_1' => ['name' => '', 'value' => 'abc']],
                'a header value was given with no header name ("abc")',
            ],
            'no value' => [
                ['_1' => ['name' => 'X-WAF-TOKEN', 'value' => '']],
                '"X-WAF-TOKEN" has no value',
            ],
            'space in the name' => [
                ['_1' => ['name' => 'X WAF TOKEN', 'value' => 'abc']],
                '"X WAF TOKEN" is not a valid HTTP header name',
            ],
            'colon in the name' => [
                ['_1' => ['name' => 'X-Waf:', 'value' => 'abc']],
                '"X-Waf:" is not a valid HTTP header name',
            ],
            'newline in the name' => [
                ['_1' => ['name' => "X-Waf\nX-Evil", 'value' => 'abc']],
                'is not a valid HTTP header name',
            ],
            'the API key header' => [
                ['_1' => ['name' => 'x-api-key', 'value' => 'abc']],
                '"x-api-key" is set by the extension itself',
            ],
            'the content type, whatever the casing' => [
                ['_1' => ['name' => 'Content-Type', 'value' => 'text/plain']],
                '"Content-Type" is set by the extension itself',
            ],
            'the browser call\'s own token' => [
                ['_1' => ['name' => 'two-delegated-authority-token', 'value' => 'abc']],
                'is set by the extension itself',
            ],
            'the same header twice' => [
                [
                    '_1' => ['name' => 'X-WAF-TOKEN', 'value' => 'abc'],
                    '_2' => ['name' => 'x-waf-token', 'value' => 'def'],
                ],
                'is listed more than once',
            ],
        ];
    }

    /**
     * Given a stored blob; When the admin form loads it; Then the grid sees
     * rows with the flag in the only shape that ticks a checkbox.
     *
     * @dataProvider storedValues
     *
     * @param array<string, mixed> $expected
     */
    public function testTheStoredValueLoadsBackAsGridRows(
        string $stored,
        array $expected,
        string $description
    ): void {
        $backend = $this->backend($stored);
        $backend->afterLoad();

        $this->assertSame($expected, $backend->getValue(), $description);
    }

    /**
     * @return array<string, array{0: string, 1: array<string, mixed>, 2: string}>
     */
    public static function storedValues(): array
    {
        return [
            'a saved table' => [
                '{"_1":{"name":"X-WAF-TOKEN","value":"abc","send_from_browser":"1"}}',
                ['_1' => ['name' => 'X-WAF-TOKEN', 'value' => 'abc', 'send_from_browser' => '1']],
                'the round trip is lossless',
            ],
            'a zero flag' => [
                '{"_1":{"name":"X-WAF-TOKEN","value":"abc","send_from_browser":"0"}}',
                ['_1' => ['name' => 'X-WAF-TOKEN', 'value' => 'abc', 'send_from_browser' => '']],
                "a '0' would tick the box, because it is a truthy string in JavaScript",
            ],
            'nothing stored' => ['', [], 'an unconfigured field renders an empty grid'],
            'junk' => ['not json at all', [], 'an unreadable value renders an empty grid, not a fatal'],
            'a json scalar' => ['"abc"', [], 'valid json that is not a row set renders an empty grid'],
        ];
    }

    /**
     * @dataProvider names
     */
    public function testUsableNamesAreTheOnesTheGateAccepts(string $name, bool $expected, string $case): void
    {
        $this->assertSame($expected, CustomHeaders::isUsableName($name), $case);
    }

    /**
     * @return array<string, array{0: string, 1: bool, 2: string}>
     */
    public static function names(): array
    {
        return [
            'token characters' => ['X-WAF-TOKEN', true, 'the ordinary case'],
            'rfc 7230 punctuation' => ["X-Wa'f!#$%&*+.^_`|~", true, 'every character a token may contain'],
            'empty' => ['', false, 'no name is not a name'],
            'space' => ['X Waf', false, 'a space ends a field name'],
            'reserved' => ['X-API-Key', false, 'the extension sets this one itself'],
        ];
    }
}
