<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Setup\Patch\Data;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Setup\Patch\Data\ClearEnglishTitleDefault;

/**
 * TWO-27108: a stored title that is exactly an old English default is
 * cleared, at its own scope; anything the merchant chose stays.
 */
class ClearEnglishTitleDefaultTest extends TestCase
{
    private const TITLE = 'payment/two_payment/title';

    public static function rows(): array
    {
        return [
            // stored row, cleared, description
            'Two' => [['default', 0, self::TITLE, 'Two'], true, 'the 2022 default'],
            'in 30 days' => [['default', 0, self::TITLE, 'Business invoice in 30 days'], true, 'an old term-carrying default'],
            'in 14 days' => [['default', 0, self::TITLE, 'Business invoice in 14 days'], true, 'an old term-carrying default'],
            'dash 30 days' => [['default', 0, self::TITLE, 'Business invoice - 30 days'], true, 'an old term-carrying default'],
            'buy now' => [['stores', 3, self::TITLE, 'Two - Buy Now Pay Later on Invoice Terms'], true, 'the last default, at a store view'],
            'website' => [['websites', 2, self::TITLE, 'Two'], true, 'a default stored at a website'],
            'other case' => [['default', 0, self::TITLE, 'two'], false, 'a title differing in case was chosen'],
            'extra space' => [['default', 0, self::TITLE, 'Two '], false, 'a title differing in spacing was chosen'],
            'merchant' => [['default', 0, self::TITLE, 'Pay by invoice'], false, 'a merchant title stays'],
            'subtitle' => [['default', 0, 'payment/two_payment/subtitle', 'Two'], false, 'the subtitle is never touched'],
            'other code' => [['default', 0, 'payment/other_payment/title', 'Two'], false, 'another method\'s title is not this module\'s default'],
        ];
    }

    #[DataProvider('rows')]
    public function testOnlyAnExactOldDefaultTitleIsCleared(array $row, bool $cleared, string $description): void
    {
        [$scope, $scopeId, $path, $value] = $row;
        $deletes = [];
        $writer = $this->createMock(WriterInterface::class);
        $writer->method('delete')->willReturnCallback(function ($p, $s, $id) use (&$deletes) {
            $deletes[] = [$p, (string)$s, (int)$id];
            return null;
        });
        $cache = $this->createMock(TypeListInterface::class);
        $cache->expects($cleared ? $this->once() : $this->never())->method('invalidate')->with('config');
        $connection = new RemoveConnection();
        $connection->rows = [['scope' => $scope, 'scope_id' => $scopeId, 'path' => $path, 'value' => $value]];

        (new ClearEnglishTitleDefault($connection->setup(), $writer, $cache))->apply();

        $this->assertSame($cleared ? [[self::TITLE, $scope, $scopeId]] : [], $deletes, $description);
        $this->assertSame('prefix_core_config_data', $connection->queriedTable, $description);
    }
}
