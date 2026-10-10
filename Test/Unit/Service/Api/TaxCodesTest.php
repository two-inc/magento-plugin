<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Service\Api;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\Config\RepositoryInterface as ConfigRepository;
use Two\Gateway\Api\Log\RepositoryInterface as LogRepository;
use Two\Gateway\Model\Config\Backend\TaxCodeMap;
use Two\Gateway\Service\Api\Adapter;
use Two\Gateway\Service\Api\TaxCodes;

/**
 * TWO-24877: the mapping dropdown's code list, and the stored mapping's format.
 */
class TaxCodesTest extends TestCase
{
    private const RESPONSE = [
        'data' => [
            ['code' => 'ES_IVA_STANDARD', 'rate' => '0.21', 'display_name' => 'IVA general',
                'requires_exemption_reason' => false, 'exemption_reason_code' => null],
            ['code' => 'ES_IVA_EXPORT', 'rate' => '0', 'display_name' => 'Exportación',
                'requires_exemption_reason' => true, 'exemption_reason_code' => 'VATEX-EU-G'],
            ['code' => 'ES_IVA_EXEMPT_OTHER', 'rate' => '0', 'display_name' => 'Exenta otros',
                'requires_exemption_reason' => true, 'exemption_reason_code' => null],
        ],
        'country_code' => 'ES',
        'metadata' => ['mandatory_tax_codes_enabled' => true, 'mandatory_tax_rates' => ['0']],
    ];

    public function testListsEveryCodeExceptThoseNeedingACallerReasonAndCachesTheAnswer(): void
    {
        $adapter = $this->createMock(Adapter::class);
        $adapter->expects($this->once())->method('execute')
            ->with('/v1/tax_codes/ES', [], 'GET', 3)->willReturn(self::RESPONSE);
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('load')->willReturn(false);
        $expected = [
            ['code' => 'ES_IVA_STANDARD', 'name' => 'IVA general', 'rate' => '0.21'],
            ['code' => 'ES_IVA_EXPORT', 'name' => 'Exportación', 'rate' => '0'],
        ];
        $cache->expects($this->once())->method('save')
            ->with(json_encode($expected), 'two_gateway_tax_codes_ES_3_sandbox', ['TWO_GATEWAY'], 86400);

        $this->assertSame($expected, $this->service($adapter, $cache)->getSelectable('es', 3));
    }

    public function testSandboxAndProductionAreCachedApart(): void
    {
        $adapter = $this->createMock(Adapter::class);
        $adapter->method('execute')->willReturn(self::RESPONSE);
        $keys = [];
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('load')->willReturnCallback(static function ($key) use (&$keys) {
            $keys[] = $key;
            return false;
        });
        foreach (['sandbox', 'production'] as $mode) {
            $config = $this->createMock(ConfigRepository::class);
            $config->method('getMode')->willReturn($mode);
            (new TaxCodes($adapter, $cache, new Json(), $this->createMock(LogRepository::class), $config))
                ->getSelectable('ES', 1);
        }

        $this->assertSame(['two_gateway_tax_codes_ES_1_sandbox', 'two_gateway_tax_codes_ES_1_production'], $keys);
    }

    public function testACachedListIsServedWithoutACall(): void
    {
        $adapter = $this->createMock(Adapter::class);
        $adapter->expects($this->never())->method('execute');
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('load')->willReturn('[{"code":"ES_IVA_EXPORT","name":"","rate":"0"}]');

        $this->assertSame(
            [['code' => 'ES_IVA_EXPORT', 'name' => '', 'rate' => '0']],
            $this->service($adapter, $cache)->getSelectable('ES', 1)
        );
    }

    /**
     * @dataProvider failedResponses
     */
    public function testAFailureIsNullAndNotCached(array $response, string $description): void
    {
        $adapter = $this->createMock(Adapter::class);
        $adapter->method('execute')->willReturn($response);
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('load')->willReturn(false);
        $cache->expects($this->never())->method('save');

        $this->assertNull($this->service($adapter, $cache)->getSelectable('ES', 1), $description);
    }

    public static function failedResponses(): array
    {
        return [
            [['error_code' => 'Country not supported', 'http_status' => 400], 'an unsupported country'],
            [['http_status' => 503], 'an outage'],
            [['country_code' => 'ES'], 'a body with no list'],
        ];
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

    /**
     * TWO-26153: a posted map that is not a JSON object is refused, never saved as an empty map.
     *
     * @dataProvider postedMaps
     */
    public function testSaveRefusesAnUnreadablePost(string $posted, ?string $stored, string $description): void
    {
        $config = $this->createMock(\Magento\Framework\App\Config\ScopeConfigInterface::class);
        $value = new TaxCodeMap(null, null, $config, null, null, null, ['value' => $posted]);
        if ($stored === null) {
            $this->expectException(\Magento\Framework\Exception\LocalizedException::class);
        }
        $value->beforeSave();
        $this->assertSame($stored, $value->getValue(), $description);
    }

    public static function postedMaps(): array
    {
        return [
            ['{"5|none":"ES_IVA_EXPORT"}', '{"5|none":"ES_IVA_EXPORT"}', 'a whole map is stored'],
            ['{}', '', 'every row on (none) stores nothing'],
            ['', '', 'an emptied field stores nothing'],
            ['{"5|none":"ES_IVA_EXP', null, 'a cut-off post is refused'],
            ['"5|none"', null, 'JSON that is not an object is refused'],
        ];
    }

    private function service(Adapter $adapter, CacheInterface $cache): TaxCodes
    {
        $config = $this->createMock(ConfigRepository::class);
        $config->method('getMode')->willReturn('sandbox');

        return new TaxCodes($adapter, $cache, new Json(), $this->createMock(LogRepository::class), $config);
    }
}
