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
 * UpdateRegionCodesFor<Country>V1 patches give the same regions. TWO-26266
 * adds their names, for registries that answer a bare code or a name.
 */
class IsoRegionResolverTest extends TestCase
{
    private const STORE_REGIONS = [
        'ES' => [161 => 'Madrid', 139 => "A Coru\u{0441}a", 171 => 'Barcelona'],
        'IT' => [500 => 'RM', 501 => 'MI'],
        'US' => [12 => 'CA', 43 => 'NY', 60 => 'AE', 61 => 'AE'],
        'DE' => [81 => 'BAY', 82 => 'BER'],
        'FR' => [182 => '1', 256 => '75'],
        'PL' => [900 => 'PL-14'],
        'AT' => [95 => 'WI'],
        'FI' => [339 => 'Uusimaa'],
        'EE' => [340 => 'EE-44'],
        'IS' => [350 => 'IS-01'],
        'CR' => [360 => 'CR-AL'],
        'IN' => [370 => 'TG', 371 => 'DN', 372 => 'DD'],
        'LV' => [380 => 'Ādažu novads'],
    ];

    private const STORE_NAMES = [
        // Core's default names; 500 also carries a store-locale name.
        'ES' => [161 => ['Madrid'], 139 => ['A Coruña'], 171 => ['Barcelona']],
        'IT' => [500 => ['Roma', 'Rome'], 501 => ['Milano']],
        'US' => [12 => ['California'], 43 => ['New York'], 60 => ['Armed Forces Europe'], 61 => ['Armed Forces Africa']],
        'FR' => [182 => ['Ain'], 256 => ['Paris']],
        'FI' => [339 => ['Uusimaa']],
    ];

    private const RECODED_NAMES = [
        // The recoding renames the community row ("Madrid, Comunidad de") and
        // leaves Cantabria's, so only Cantabria's province and community share
        // a name.
        'ES' => [
            161 => ['Madrid, Comunidad de'], 171 => ['Barcelona'], 990 => ['Madrid'],
            991 => ['Illes Balears [Islas Baleares]'], 992 => ['Cantabria'], 993 => ['Cantabria'],
        ],
    ];

    private const RECODED_REGIONS = [
        // The recoding turns "Madrid" into the community's ES-MD and adds a
        // separate ES-M province row; Baleares becomes ES-IB with no ES-PM row.
        'ES' => [161 => 'ES-MD', 171 => 'ES-B', 990 => 'ES-M', 991 => 'ES-IB', 992 => 'ES-CB', 993 => 'ES-S'],
        'DE' => [81 => 'BY'],
    ];

    /**
     * @return array<string, array<int, mixed>>
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
            'in-dh' => [$core, 'IN', 'IN-DH', null, 'a merged territory is not either older half'],
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
            'bare code' => [$core, 'IT', 'RM', [500, 'RM'], 'a bare province code matches the store code (TWO-26266)'],
            'bare code lower' => [$core, 'IT', ' rm ', [500, 'RM'], 'a bare code is matched case-insensitively'],
            'shared code' => [$core, 'US', 'AE', null, 'a code two regions share selects neither'],
            'code is a name' => [$core, 'ES', 'MADRID', [161, 'Madrid'], "a store coding regions by name matches the code"],
            'name' => [$core, 'IT', 'ROMA', [500, 'RM'], 'a region name matches the default name'],
            'locale name' => [$core, 'IT', 'rome', [500, 'RM'], "a region name matches the store locale's name"],
            'accents' => [$core, 'ES', 'A CORUNA', [139, "A Coru\u{0441}a"], 'a name is matched ignoring accents'],
            'name only whole' => [$core, 'US', 'Armed Forces', null, 'part of a name matches nothing'],
            'no fuzzy' => [$core, 'FR', 'ILE DE FRANCE', null, 'a region the store does not list matches nothing'],
            'foreign name' => [$core, 'IT', 'Madrid', null, "another country's region name matches nothing"],
            'renamed community' => [$recoded, 'ES', 'Madrid', [990, 'ES-M'], 'the province keeps the name core takes off its community', self::RECODED_NAMES],
            'shared name' => [$recoded, 'ES', 'Cantabria', null, 'a name two regions share selects neither', self::RECODED_NAMES],
            'foreign bare iso' => [$core, 'IT', 'ES-M', null, "another country's code still matches nothing"],
            'no regions' => [$core, 'NL', 'NL-NH', null, 'a country with no regions matches nothing'],
        ];
    }

    /**
     * @param array<string, array<int, string>> $storeRegions
     * @param mixed $region
     * @param array{int, string}|null $expected
     * @param array<string, array<int, string[]>> $storeNames
     */
    #[DataProvider('cases')]
    public function testResolvesTheStoreRegionForAnIsoCode(
        array $storeRegions,
        string $country,
        $region,
        ?array $expected,
        string $description,
        array $storeNames = self::STORE_NAMES
    ): void {
        $directory = $this->createMock(RegionDirectory::class);
        $directory->method('forCountry')->willReturnCallback(
            static fn(string $countryId) => $storeRegions[$countryId] ?? []
        );
        $directory->method('namesForCountry')->willReturnCallback(
            static fn(string $countryId) => $storeNames[strtoupper($countryId)] ?? []
        );

        $this->assertSame(
            $expected === null ? null : ['region_id' => $expected[0], 'region_code' => $expected[1]],
            (new IsoRegionResolver($directory))->resolve($country, $region),
            $description
        );
    }

    public function testAFailedRegionReadIsLoggedAndMatchesNothing(): void
    {
        // The bootstrap may stub the factory without its generated create().
        $builder = $this->getMockBuilder(CollectionFactory::class)->disableOriginalConstructor();
        $factory = method_exists(CollectionFactory::class, 'create')
            ? $builder->onlyMethods(['create'])->getMock()
            : $builder->addMethods(['create'])->getMock();
        $factory->method('create')->willThrowException(new \RuntimeException('directory unavailable'));
        $log = $this->createMock(LogRepository::class);
        $log->expects($this->once())->method('addErrorLog');

        $this->assertNull((new IsoRegionResolver(new RegionDirectory($factory, $log)))->resolve('ES', 'ES-M'));
    }

    public function testTheDirectoryReadsEachRegionsDefaultAndLocaleNamesOnce(): void
    {
        $region = static fn(int $id, string $code, string $default, ?string $name) => new class ($id, $code, $default, $name) {
            public function __construct(
                private readonly int $id,
                private readonly string $code,
                private readonly string $default,
                private readonly ?string $name
            ) {
            }
            public function getRegionId(): int
            {
                return $this->id;
            }
            public function getCode(): string
            {
                return $this->code;
            }
            public function getDefaultName(): string
            {
                return $this->default;
            }
            public function getName(): ?string
            {
                return $this->name;
            }
        };
        $collection = new class ([$region(500, 'RM', 'Roma', 'Rome'), $region(501, 'MI', 'Milano', null)])
            implements \IteratorAggregate {
            public function __construct(private readonly array $rows)
            {
            }
            public function addCountryFilter(string $countryId): self
            {
                return $this;
            }
            public function getIterator(): \ArrayIterator
            {
                return new \ArrayIterator($this->rows);
            }
        };
        $builder = $this->getMockBuilder(CollectionFactory::class)->disableOriginalConstructor();
        $factory = method_exists(CollectionFactory::class, 'create')
            ? $builder->onlyMethods(['create'])->getMock()
            : $builder->addMethods(['create'])->getMock();
        $factory->expects($this->once())->method('create')->willReturn($collection);
        $directory = new RegionDirectory($factory, $this->createMock(LogRepository::class));

        $this->assertSame([500 => 'RM', 501 => 'MI'], $directory->forCountry('IT'));
        $this->assertSame([500 => ['Roma', 'Rome'], 501 => ['Milano']], $directory->namesForCountry('IT'));
    }

    public function testEnrichLeavesAnAddressWithNoCountryAlone(): void
    {
        $directory = $this->createMock(RegionDirectory::class);
        $directory->expects($this->never())->method('forCountry');
        $address = ['city' => 'MADRID', 'region' => 'ES-M'];

        $this->assertSame($address, (new IsoRegionResolver($directory))->enrich($address));
    }
}
