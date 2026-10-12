<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Model\Config;

use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\Config\RepositoryInterface as ConfigRepository;
use Two\Gateway\Model\Config\TaxCodeSectionResolver;
use Two\Gateway\Service\Api\TaxCodes;
use Two\Gateway\Service\Merchant\ApiKeyStatus;
use Two\Gateway\Service\Merchant\RecordProvider;

/**
 * TWO-26153: the facts the tax code section decides on, read at the scope being edited.
 */
class TaxCodeSectionResolverTest extends TestCase
{
    /**
     * @dataProvider cases
     * @param array<string, mixed> $expected the state's keys that matter for the case
     */
    public function testResolve(
        string $scope,
        string $key,
        ?array $record,
        array $verdict,
        ?array $list,
        array $websites,
        int $listCalls,
        array $expected,
        string $description
    ): void {
        $config = $this->createMock(ConfigRepository::class);
        $config->method('getApiKey')->willReturnCallback(static function ($id, $scopeType) use ($key, $websites) {
            return $scopeType === 'website' ? ($websites[$id] ?? $key) : $key;
        });
        $config->method('getMode')->willReturn('sandbox');
        $records = $this->createMock(RecordProvider::class);
        $records->expects($key === '' ? $this->never() : $this->once())->method('getRecord')
            ->with(5, $scope)->willReturn($record);
        $status = $this->createMock(ApiKeyStatus::class);
        $status->method('getStatus')->willReturn($verdict);
        $taxCodes = $this->createMock(TaxCodes::class);
        $taxCodes->expects($this->exactly($listCalls))->method('getList')
            ->willReturnCallback(static function ($country, $id, $scopeType, &$failed) use ($list) {
                $failed = $list === null ? 503 : 0;
                return $list;
            });
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getWebsites')->willReturn(array_map(static function ($id) {
            return new class ($id) {
                private $id;

                public function __construct($id)
                {
                    $this->id = $id;
                }

                public function getId()
                {
                    return $this->id;
                }
            };
        }, array_keys($websites)));

        $state = (new TaxCodeSectionResolver($config, $records, $status, $taxCodes, $storeManager))
            ->resolve(5, $scope, '{"2|none":"ES_IVA_EXPORT"}');

        $this->assertSame($expected, array_intersect_key($state, $expected), $description);
    }

    public static function cases(): array
    {
        $es = ['country_code' => 'es'];
        $mandatory = ['codes' => [], 'mandatory' => true];
        $ok = ['status' => ApiKeyStatus::OK, 'code' => 200, 'merchant' => null];

        return [
            ['store', 'key', $es, $ok, $mandatory, [], 1, ['view' => 'rows', 'country' => 'ES'], 'the record gives the country'],
            ['store', '', $es, $ok, $mandatory, [], 0, ['view' => 'hidden'], 'no key asks nothing'],
            ['store', 'key', null, ['status' => ApiKeyStatus::SERVICE_ERROR, 'code' => 503, 'merchant' => null], $mandatory, [], 0,
                ['view' => 'profile_error', 'rejected' => false, 'http' => 503], 'no record: E1 with the verdict status'],
            ['store', 'key', ['country_code' => ''], ['status' => ApiKeyStatus::INVALID_KEY, 'code' => 401, 'merchant' => null], $mandatory, [], 0,
                ['view' => 'profile_error', 'rejected' => true], 'a refused key: E1b'],
            ['store', 'key', $es, $ok, null, [], 1, ['view' => 'list_error', 'http' => 503], 'no list: E2'],
            ['default', 'key', $es, $ok, $mandatory, [1 => 'key', 2 => 'other'], 1, ['notices' => ['SCOPE']], 'default scope, a website with another key'],
            ['default', 'key', $es, $ok, $mandatory, [1 => 'key'], 1, ['notices' => []], 'default scope, every website alike'],
            ['website', 'key', $es, $ok, $mandatory, [1 => 'other'], 1, ['notices' => []], 'a website scope has one key'],
        ];
    }
}
