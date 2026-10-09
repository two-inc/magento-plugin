<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Service\Address;

use PHPUnit\Framework\Attributes\DataProvider;
use Magento\Directory\Model\ResourceModel\Region\CollectionFactory;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\Log\RepositoryInterface as LogRepository;
use Two\Gateway\Service\Address\IsoRegionResolver;
use Two\Gateway\Service\Address\RegionDirectory;

/**
 * TWO-26263. Region rows are excerpts of Magento's released core directory
 * data, ids invented; the "recoded" rows are the codes core's later
 * UpdateRegionCodesFor<Country>V1 patches give the same regions.
 */
class IsoRegionResolverTest extends TestCase
{
    private const STORE_REGIONS = [
        'ES' => [161 => 'Madrid', 139 => "A Coru\u{0441}a", 171 => 'Barcelona'],
        'IT' => [500 => 'RM', 501 => 'MI'],
        'US' => [12 => 'CA', 43 => 'NY'],
        'DE' => [81 => 'BAY', 82 => 'BER'],
        'FR' => [182 => '1', 256 => '75'],
        'PL' => [900 => 'PL-14'],
        'AT' => [95 => 'WI'],
        'FI' => [339 => 'Uusimaa'],
        'EE' => [340 => 'EE-44'],
        'IS' => [350 => 'IS-01'],
        'CR' => [360 => 'CR-AL'],
        'IN' => [370 => 'TG'],
        'LV' => [380 => 'Ādažu novads'],
    ];

    private const RECODED_REGIONS = [
        // The recoding turns "Madrid" into the community's ES-MD and adds a
        // separate ES-M province row; Baleares becomes ES-IB with no ES-PM row.
        'ES' => [161 => 'ES-MD', 171 => 'ES-B', 990 => 'ES-M', 991 => 'ES-IB'],
        'DE' => [81 => 'BY'],
    ];

    /**
     * @return array<string, array{array<string, array<int, string>>, string, mixed, ?array, string}>
     */
    public static function cases(): array
    {
        $core = self::STORE_REGIONS;
        $recoded = self::RECODED_REGIONS;

        return [
            'es-m' => [$core, 'ES', 'ES-M', [161, 'Madrid'], 'a Spanish province resolves through the table'],
            'es-c' => [$core, 'ES', 'ES-C', [139, "A Coru\u{0441}a"], "core's own spelling of A Coruna is matched"],
            'it-rm' => [$core, 'IT', 'IT-RM', [500, 'RM'], 'an Italian province matches its suffix (Roma)'],
            'us-ca' => [$core, 'US', 'US-CA', [12, 'CA'], 'a US state matches its suffix'],
            'de-by' => [$core, 'DE', 'DE-BY', [81, 'BAY'], 'a German state resolves through the table (Bayern)'],
            'at-9' => [$core, 'AT', 'AT-9', [95, 'WI'], 'an Austrian state resolves through the table'],
            'fi-18' => [$core, 'FI', 'FI-18', [339, 'Uusimaa'], 'a Finnish region resolves through the table'],
            'fr-01' => [$core, 'FR', 'FR-01', [182, '1'], 'a numeric suffix is compared as a number'],
            'fr-75c' => [$core, 'FR', 'FR-75C', [256, '75'], 'Paris resolves through the table'],
            'ee-45' => [$core, 'EE', 'EE-45', [340, 'EE-44'], 'an Estonian code core numbered differently'],
            'is-1' => [$core, 'IS', 'IS-1', [350, 'IS-01'], 'an Icelandic code core zero-pads'],
            'cr-a' => [$core, 'CR', 'CR-A', [360, 'CR-AL'], 'a Costa Rican province resolves through the table'],
            'in-ts' => [$core, 'IN', 'IN-TS', [370, 'TG'], 'an Indian state core codes by its older code'],
            'lv-011' => [$core, 'LV', 'LV-011', [380, 'Ādažu novads'], 'a Latvian municipality core codes by name'],
            'pl-14' => [$core, 'PL', 'PL-14', [900, 'PL-14'], 'a store coding regions as full ISO codes matches whole'],
            'lower' => [$core, 'es', ' es-m ', [161, 'Madrid'], 'country and code are matched case-insensitively'],
            'recoded es-b' => [$recoded, 'ES', 'ES-B', [171, 'ES-B'], 'a recoded store matches the whole code'],
            'recoded es-m' => [$recoded, 'ES', 'ES-M', [990, 'ES-M'], "the recoding's own province row wins"],
            'recoded es-pm' => [$recoded, 'ES', 'ES-PM', [991, 'ES-IB'], 'a province core gives only its community code'],
            'recoded de-by' => [$recoded, 'DE', 'DE-BY', [81, 'BY'], 'a recoded German store matches the suffix'],
            'foreign' => [$core, 'ES', 'FR-75', null, "another country's code is not the address's region"],
            'unknown' => [$core, 'ES', 'ES-XX', null, 'an unknown suffix matches nothing'],
            'empty' => [$core, 'ES', '', null, 'an empty region matches nothing'],
            'null' => [$core, 'ES', null, null, 'an absent region matches nothing'],
            'free text' => [$core, 'ES', 'Madrid', null, 'a region name is not a code'],
            'no regions' => [$core, 'NL', 'NL-NH', null, 'a country with no regions matches nothing'],
        ];
    }

    /**
     * @param array<string, array<int, string>> $storeRegions
     * @param mixed $region
     * @param array{int, string}|null $expected
     */
    #[DataProvider('cases')]
    public function testResolvesTheStoreRegionForAnIsoCode(
        array $storeRegions,
        string $country,
        $region,
        ?array $expected,
        string $description
    ): void {
        $directory = $this->createMock(RegionDirectory::class);
        $directory->method('forCountry')->willReturnCallback(
            static fn(string $countryId) => $storeRegions[$countryId] ?? []
        );

        $this->assertSame(
            $expected === null ? null : ['region_id' => $expected[0], 'region_code' => $expected[1]],
            (new IsoRegionResolver($directory))->resolve($country, $region),
            $description
        );
    }

    public function testAFailedRegionReadIsLoggedAndMatchesNothing(): void
    {
        $factory = new class extends CollectionFactory {
            public function create()
            {
                throw new \RuntimeException('directory unavailable');
            }
        };
        $log = $this->createMock(LogRepository::class);
        $log->expects($this->once())->method('addErrorLog');

        $this->assertNull((new IsoRegionResolver(new RegionDirectory($factory, $log)))->resolve('ES', 'ES-M'));
    }

    public function testEnrichLeavesAnAddressWithNoCountryAlone(): void
    {
        $directory = $this->createMock(RegionDirectory::class);
        $directory->expects($this->never())->method('forCountry');
        $address = ['city' => 'MADRID', 'region' => 'ES-M'];

        $this->assertSame($address, (new IsoRegionResolver($directory))->enrich($address));
    }
}
