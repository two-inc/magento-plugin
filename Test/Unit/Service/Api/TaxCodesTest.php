<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Service\Api;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\FlagManager;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\Config\RepositoryInterface as ConfigRepository;
use Two\Gateway\Api\Log\RepositoryInterface as LogRepository;
use Two\Gateway\Model\Config\Backend\TaxCodeMap;
use Two\Gateway\Service\Api\Adapter;
use Two\Gateway\Service\Api\TaxCodes;

/**
 * TWO-24877, TWO-26153: the code list the tax code section offers, with whether
 * codes are mandatory at 0%, and the stored mapping's format.
 */
class TaxCodesTest extends TestCase
{
    private const DATA = [
        ['code' => 'ES_IVA_STANDARD', 'rate' => '0.21', 'display_name' => 'IVA general',
            'requires_exemption_reason' => false, 'exemption_reason_code' => null],
        ['code' => 'ES_IVA_EXPORT', 'rate' => '0', 'display_name' => 'Exportación',
            'requires_exemption_reason' => true, 'exemption_reason_code' => 'VATEX-EU-G'],
        ['code' => 'ES_IVA_EXEMPT_OTHER', 'rate' => '0', 'display_name' => 'Exenta otros',
            'requires_exemption_reason' => true, 'exemption_reason_code' => null],
        ['code' => 'ES_IVA_EXEMPT_BLANK', 'rate' => '0', 'display_name' => 'Exenta',
            'requires_exemption_reason' => true, 'exemption_reason_code' => ''],
    ];

    private const ES = [
        ['code' => 'ES_IVA_STANDARD', 'name' => 'IVA general', 'rate' => '0.21'],
        ['code' => 'ES_IVA_EXPORT', 'name' => 'Exportación', 'rate' => '0'],
    ];

    /** @var array<string, array{0: string, 1: array, 2: int|null}> */
    private $store = [];

    /** @var array<string, mixed> the flag table */
    private $flags = [];

    /**
     * @dataProvider answers
     */
    public function testAnAnswerIsCachedADayAndKeptAsTheLastAnswer(array $response, array $expected, string $description): void
    {
        $adapter = $this->createMock(Adapter::class);
        $adapter->expects($this->once())->method('executeWithStatus')
            ->with('/v1/tax_codes/ES', [], 'GET', 3, 'key', 'sandbox', 10)->willReturn($response);
        $status = -1;

        $this->assertSame($expected, $this->service($adapter)->getList('es', 3, null, $status), $description);
        $this->assertSame(0, $status, $description);
        $this->assertSame(86400, $this->store['two_gateway_tax_codes_ES_sandbox'][2], $description);
        $this->assertSame(['TWO_GATEWAY', 'TWO_GATEWAY_TAX_CODES'], $this->store['two_gateway_tax_codes_ES_sandbox'][1], $description);
        $this->assertSame($expected, $this->flags['two_gateway_tax_codes_last_ES_sandbox'], $description . ': kept in the flag table, which a cache flush does not clear');
        $this->assertArrayNotHasKey('two_gateway_tax_codes_ES_sandbox_last', $this->store, $description);
        $this->assertSame(300, $this->store['two_gateway_tax_codes_ES_sandbox_tried'][2], $description);
    }

    public static function answers(): array
    {
        return [
            [['status' => 200, 'body' => ['data' => self::DATA, 'metadata' => ['mandatory_tax_codes_enabled' => true, 'mandatory_tax_rates' => ['0']]]],
                ['codes' => self::ES, 'mandatory' => true], 'mandatory at 0%; codes needing a reason with no default dropped'],
            [['status' => 200, 'body' => ['data' => self::DATA, 'metadata' => ['mandatory_tax_codes_enabled' => true, 'mandatory_tax_rates' => ['0.21']]]],
                ['codes' => self::ES, 'mandatory' => false], 'mandatory only above 0%'],
            [['status' => 200, 'body' => ['data' => self::DATA]], ['codes' => self::ES, 'mandatory' => false], 'no metadata'],
            [['status' => 400, 'body' => ['error_code' => 'COUNTRY_NOT_SUPPORTED', 'http_status' => 400]],
                ['codes' => [], 'mandatory' => false], 'no registry is an answer: no codes, not mandatory'],
        ];
    }

    /**
     * @dataProvider failures
     */
    public function testAFailureServesTheLastAnswerOrNothingAndWaitsOutTheFloor(array $response, bool $last, string $description): void
    {
        $adapter = $this->createMock(Adapter::class);
        $adapter->expects($this->once())->method('executeWithStatus')->willReturn($response);
        $held = ['codes' => self::ES, 'mandatory' => true];
        if ($last) {
            $this->flags['two_gateway_tax_codes_last_ES_sandbox'] = $held;
        }
        $service = $this->service($adapter);
        $status = -1;

        $this->assertSame($last ? $held : null, $service->getList('ES', 1, null, $status), $description);
        $this->assertSame($last ? 0 : (int)$response['status'], $status, $description);
        $this->assertArrayNotHasKey('two_gateway_tax_codes_ES_sandbox', $this->store, $description);
        $this->assertSame($last ? $held : null, $service->getList('ES', 1), $description . ': no second call inside the floor');
    }

    public static function failures(): array
    {
        return [
            [['status' => 503, 'body' => ['http_status' => 503]], false, 'an outage, nothing held'],
            [['status' => 503, 'body' => ['http_status' => 503]], true, 'an outage serves the last answer'],
            [['status' => 400, 'body' => ['error_code' => 'OTHER', 'http_status' => 400]], false, 'any other 400 is a failure'],
            [['status' => 200, 'body' => ['country_code' => 'ES']], false, 'a body with no list'],
            [['status' => 0, 'body' => ['error_code' => 400]], true, 'a transport failure serves the last answer'],
        ];
    }

    public function testAFreshAnswerIsServedWithoutACallAndAnOldEntryWithoutMetadataIsNone(): void
    {
        $adapter = $this->createMock(Adapter::class);
        $adapter->expects($this->once())->method('executeWithStatus')
            ->willReturn(['status' => 200, 'body' => ['data' => []]]);
        $this->store['two_gateway_tax_codes_ES_sandbox'] = ['{"codes":[],"mandatory":true}', [], 86400];
        $this->store['two_gateway_tax_codes_FR_sandbox'] = ['[{"code":"FR_X","name":"","rate":"0"}]', [], 86400];
        $service = $this->service($adapter);

        $this->assertSame(['codes' => [], 'mandatory' => true], $service->getList('ES', 1), 'fresh: no call');
        $this->assertSame(['codes' => [], 'mandatory' => false], $service->getList('FR', 1), 'an entry from before the metadata is fetched again');
    }

    public function testSandboxAndProductionAreCachedApartAndAWebsiteSendsNoStoreId(): void
    {
        $adapter = $this->createMock(Adapter::class);
        $adapter->expects($this->exactly(2))->method('executeWithStatus')
            ->with('/v1/tax_codes/ES', [], 'GET', null, 'key', $this->anything(), 10)
            ->willReturn(['status' => 200, 'body' => ['data' => []]]);
        foreach (['sandbox', 'production'] as $mode) {
            $this->service($adapter, $mode)->getList('ES', 1, 'website');
        }

        $this->assertArrayHasKey('two_gateway_tax_codes_ES_sandbox', $this->store);
        $this->assertArrayHasKey('two_gateway_tax_codes_ES_production', $this->store);
    }

    public function testExpireDropsTheDayLongAnswersAndFloorsAndKeepsTheLastAnswers(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->expects($this->once())->method('clean')->with(['TWO_GATEWAY_TAX_CODES']);
        (new TaxCodes($this->createMock(Adapter::class), $cache, new Json(), $this->createMock(LogRepository::class), $this->createMock(ConfigRepository::class), $this->createMock(FlagManager::class)))
            ->expire();
    }

    public function testAnInvalidCountryAsksNothing(): void
    {
        $adapter = $this->createMock(Adapter::class);
        $adapter->expects($this->never())->method('executeWithStatus');

        $this->assertNull($this->service($adapter)->getList('', 1));
    }

    /**
     * @dataProvider storedMaps
     * @param mixed $value
     */
    public function testTheMappingKeepsOnlyUsableEntries($value, array $expected, string $description): void
    {
        $this->assertSame($expected, TaxCodeMap::normalise($value), $description);
    }

    public static function storedMaps(): array
    {
        return [
            ['', [], 'nothing stored'],
            ['not json', [], 'junk'],
            ['{"5|none":"ES_IVA_EXPORT","2|exempt":"ES_IVA_INTRA_COMMUNITY"}', ['2|exempt' => 'ES_IVA_INTRA_COMMUNITY', '5|none' => 'ES_IVA_EXPORT'], 'stored JSON'],
            ['{"5|rate:ES CANARIAS [0]":"ES_IVA_EXPORT","6|none":"","0|exempt":"ES_IVA_EXEMPT_ART20"}', ['0|exempt' => 'ES_IVA_EXEMPT_ART20', '5|rate:ES CANARIAS [0]' => 'ES_IVA_EXPORT'], 'posted JSON, (none) dropped, any rate code kept'],
            [['10|none' => 'ES_IVA_EXPORT', '9|none' => 'ES_IVA_EXPORT'], ['9|none' => 'ES_IVA_EXPORT', '10|none' => 'ES_IVA_EXPORT'], 'classes in numeric order'],
            [['5' => 'ES_IVA_EXPORT', 'x|none' => 'ES_IVA_EXPORT', '5|rate:' => 'ES_IVA_EXPORT', '5|other' => 'ES_IVA_EXPORT', '5|none' => 'es iva', '6|none' => ['ES_IVA_EXPORT']], [], 'the old class key, bad row keys and bad codes'],
        ];
    }

    private function service(Adapter $adapter, string $mode = 'sandbox'): TaxCodes
    {
        $config = $this->createMock(ConfigRepository::class);
        $config->method('getMode')->willReturn($mode);
        $config->method('getApiKey')->willReturn('key');
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('load')->willReturnCallback(function ($key) {
            return $this->store[$key][0] ?? false;
        });
        $cache->method('save')->willReturnCallback(function ($data, $key, $tags = [], $lifetime = null) {
            $this->store[$key] = [$data, $tags, $lifetime];
            return true;
        });

        $flags = $this->createMock(FlagManager::class);
        $flags->method('getFlagData')->willReturnCallback(function ($code) {
            return $this->flags[$code] ?? null;
        });
        $flags->method('saveFlag')->willReturnCallback(function ($code, $value) {
            $this->flags[$code] = $value;
            return true;
        });

        return new TaxCodes($adapter, $cache, new Json(), $this->createMock(LogRepository::class), $config, $flags);
    }
}
