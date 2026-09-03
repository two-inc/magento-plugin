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
            'surrounding spaces and tabs are trimmed' => [
                ['_1' => ['name' => '  X-WAF-TOKEN ', 'value' => "\tabc "]],
                ['_1' => ['name' => 'X-WAF-TOKEN', 'value' => 'abc', 'send_from_browser' => '']],
                'a pasted value carries whitespace a header cannot',
            ],
            'the printable ASCII boundaries' => [
                ['_1' => ['name' => 'X-Waf', 'value' => 'a b~c!']],
                ['_1' => ['name' => 'X-Waf', 'value' => 'a b~c!', 'send_from_browser' => '']],
                'space and tilde are the first and last characters the rule allows',
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
                'row 1 has a value but no header name',
            ],
            'no name, later row' => [
                [
                    '_1' => ['name' => 'X-Fine', 'value' => 'ok'],
                    '_2' => ['name' => '', 'value' => 'abc'],
                ],
                'row 2 has a value but no header name',
            ],
            'carriage return in the value' => [
                ['_1' => ['name' => 'X-Waf', 'value' => "abc\r\nX-API-Key: forged"]],
                'may only contain printable ASCII characters',
            ],
            'bare newline in the value' => [
                ['_1' => ['name' => 'X-Waf', 'value' => "abc\nX-API-Key: forged"]],
                'may only contain printable ASCII characters',
            ],
            'null byte in the value' => [
                ['_1' => ['name' => 'X-Waf', 'value' => "abc\0def"]],
                'may only contain printable ASCII characters',
            ],
            'non-ASCII bytes' => [
                ['_1' => ['name' => 'X-Waf', 'value' => "abc\xB1\x31"]],
                'may only contain printable ASCII characters',
            ],
            'accented text' => [
                ['_1' => ['name' => 'X-Waf', 'value' => 'café']],
                'may only contain printable ASCII characters',
            ],
            'an interior tab' => [
                ['_1' => ['name' => 'X-Waf', 'value' => "abc\tdef"]],
                'may only contain printable ASCII characters',
            ],
            'a trailing newline is refused, not stripped' => [
                ['_1' => ['name' => 'X-Waf', 'value' => "abc\n"]],
                'may only contain printable ASCII characters',
            ],
            'the delete control character' => [
                ['_1' => ['name' => 'X-Waf', 'value' => "abc\x7Fdef"]],
                'may only contain printable ASCII characters',
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
     * Given a name the extension sets itself, or a proxy-identity header;
     * When the admin lists it, however cased; Then the save is refused.
     *
     * @dataProvider reservedNames
     */
    public function testAReservedNameIsRefusedWhateverItsCasing(string $name): void
    {
        foreach ([$name, strtoupper($name), ucwords($name, '-')] as $cased) {
            $this->assertFalse(
                CustomHeaders::isUsableName($cased),
                sprintf('%s must be reserved', $cased)
            );

            try {
                $this->save(['_1' => ['name' => $cased, 'value' => 'anything']]);
                $this->fail(sprintf('%s must be refused at save', $cased));
            } catch (LocalizedException $e) {
                $this->assertStringContainsString('is set by the extension itself', $e->getMessage());
            }
        }
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function reservedNames(): array
    {
        $names = [
            // Set by the integration itself.
            'host',
            'content-type',
            'content-length',
            'accept',
            'accept-language',
            'x-api-key',
            'two-delegated-authority-token',
            // Proxy identity the rate limiter resolves callers through.
            'x-forwarded-for',
            'x-real-ip',
            // RFC 7230 hop-by-hop: connection handling, not request content.
            'connection',
            'keep-alive',
            'proxy-authenticate',
            'proxy-authorization',
            'te',
            'trailer',
            'transfer-encoding',
            'upgrade',
            // Generic credential carriers.
            'authorization',
            'cookie',
        ];

        return array_combine($names, array_map(static fn(string $name) => [$name], $names));
    }

    /**
     * A name near a reserved one is still the admin's to use.
     *
     * @dataProvider namesNearAReservedOne
     */
    public function testANameMerelyResemblingAReservedOneIsAccepted(string $name): void
    {
        $this->assertTrue(CustomHeaders::isUsableName($name));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function namesNearAReservedOne(): array
    {
        return [
            'prefixed' => ['X-Accept'],
            'suffixed' => ['accept-charset'],
            'the WAF token the retired field used' => ['X-WAF-TOKEN'],
            'a merchant gateway name' => ['X-Gateway-Id'],
            'longer than a short reserved name' => ['tenant'],
            'a hop-by-hop lookalike' => ['X-Upgrade-Path'],
            'an authorization lookalike' => ['X-Authorization-Scheme'],
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
     * The read path re-applies this, so a value written straight to
     * core_config_data cannot forge a header either.
     *
     * @dataProvider values
     */
    public function testSendableValuesAreTheOnesTheGateAccepts(string $value, bool $expected, string $case): void
    {
        $this->assertSame($expected, CustomHeaders::isSendableValue($value), $case);
    }

    /**
     * @return array<string, array{0: string, 1: bool, 2: string}>
     */
    public static function values(): array
    {
        return [
            'ordinary' => ['waf-token', true, 'the ordinary case'],
            'spaces inside' => ['two words', true, 'a header value may contain spaces'],
            'punctuation' => ['a=b; c="d", e/f?g&h', true, 'printable ASCII is printable ASCII'],
            'first allowed character' => [' x', true, 'space is 0x20, the low boundary'],
            'last allowed character' => ['~', true, 'tilde is 0x7E, the high boundary'],
            'empty' => ['', false, 'nothing to send'],
            'crlf' => ["abc\r\nX-API-Key: forged", false, 'would close the header and forge the next'],
            'lf' => ["abc\nfoo", false, 'a bare newline is enough'],
            'trailing lf' => ["abc\n", false, 'the byte a regex anchored on $ would have let through'],
            'cr' => ["abc\rfoo", false, 'so is a bare carriage return'],
            'nul' => ["abc\0foo", false, 'truncates the header in a C string'],
            'tab' => ["abc\tfoo", false, 'a control character, however harmless it looks'],
            'vertical tab' => ["abc\x0Bfoo", false, 'still a control character'],
            'unit separator' => ["abc\x1Ffoo", false, '0x1F is the byte below the low boundary'],
            'escape' => ["abc\x1Bfoo", false, 'terminal escape, a log-injection sink'],
            'delete' => ["abc\x7Ffoo", false, '0x7F is above the printable range'],
            'high byte' => ["abc\xB1", false, 'non-ASCII is encoding-ambiguous on the wire'],
            'accented text' => ['café', false, 'valid UTF-8 is still not ASCII'],
        ];
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
