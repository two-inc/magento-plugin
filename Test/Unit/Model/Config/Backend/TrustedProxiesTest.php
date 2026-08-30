<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Model\Config\Backend;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\Context;
use Magento\Framework\Registry;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Model\Config\Backend\TrustedProxies;

/**
 * The trusted-proxy list is refused at entry unless every entry parses,
 * because the limiter treats an entry it cannot parse as matching nothing.
 */
class TrustedProxiesTest extends TestCase
{
    private function buildModel(string $value): TrustedProxies
    {
        return new TrustedProxies(
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
     * Given a malformed entry; When the section is saved; Then the save is
     * refused naming the entry, rather than storing a rule that silently
     * matches nothing.
     *
     * @dataProvider rejectedEntries
     */
    public function testAMalformedEntryIsRefusedAtSave(string $value): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessageMatches('/is not a valid IP address or CIDR range/');

        $this->buildModel($value)->beforeSave();
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function rejectedEntries(): array
    {
        return [
            'bare slash' => ['10.0.0.0/'],
            'non-numeric suffix' => ['10.0.0.0/abc'],
            'negative suffix' => ['10.0.0.0/-1'],
            'fractional suffix' => ['10.0.0.0/8.5'],
            'suffix past the address width' => ['10.0.0.0/33'],
            'ipv6 suffix past the address width' => ['2001:db8::/129'],
            'not an address at all' => ['proxy.example.com'],
            'malformed beside valid' => ["192.168.0.0/16\n10.0.0.0/"],
            // A zero width parses cleanly and names every address of its
            // family, so it is the one entry the admin must not be able to
            // store believing they have named a proxy.
            'zero suffix' => ['10.0.0.0/0'],
            'zero suffix padded' => ['10.0.0.0/00'],
            'zero suffix padded further' => ['10.0.0.0/000'],
            'ipv4 match-everything block' => ['0.0.0.0/0'],
            'ipv6 match-everything block' => ['::/0'],
            'ipv6 zero suffix' => ['2001:db8::/0'],
            'zero suffix beside valid' => ["192.168.0.0/16\n0.0.0.0/0"],
        ];
    }

    /**
     * Given valid entries in any accepted separator or spelling; When the
     * section is saved; Then they are stored one per line, canonical and
     * deduplicated.
     *
     * @dataProvider acceptedEntries
     */
    public function testValidEntriesAreStoredCanonically(string $value, string $expected): void
    {
        $model = $this->buildModel($value);
        $model->beforeSave();

        $this->assertSame($expected, $model->getValue());
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function acceptedEntries(): array
    {
        return [
            'empty stays empty' => ['', ''],
            'whitespace only' => ["  \n ", ''],
            'single address' => ['198.51.100.7', '198.51.100.7'],
            'comma separated' => ['10.0.0.0/8, 192.0.2.1', "10.0.0.0/8\n192.0.2.1"],
            'newline separated' => ["10.0.0.0/8\n192.0.2.1", "10.0.0.0/8\n192.0.2.1"],
            'ipv6 zero padding is canonicalised' => ['2001:0db8::1', '2001:db8::1'],
            'ipv6 expanded run is canonicalised' => ['2001:db8:0:0:0:0:0:1', '2001:db8::1'],
            'ipv6 range keeps its width' => ['2001:0db8::/32', '2001:db8::/32'],
            'duplicate spellings collapse' => ['2001:0db8::1, 2001:db8::1', '2001:db8::1'],
            'full-width ranges' => ['10.0.0.0/32, 2001:db8::/128', "10.0.0.0/32\n2001:db8::/128"],
            // Only the numerically zero width is refused; a padded real one is
            // decimal and names the range the admin meant.
            'padded width is decimal' => ['10.0.0.0/008', '10.0.0.0/8'],
            'narrowest range' => ['10.0.0.0/1', '10.0.0.0/1'],
        ];
    }
}
