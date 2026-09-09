<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Setup\Patch\Data;

use Magento\Framework\App\Cache\Manager as CacheManager;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Setup\Patch\Data\EnableGatewayCacheType;

class EnableGatewayCacheTypeTest extends TestCase
{
    /**
     * @return array<int, array{0: string, 1: bool, 2: string}>
     */
    public static function cacheTypeProvider(): array
    {
        $rows = [];
        foreach (self::typesDeclaredInCacheXml() as $type) {
            $rows[] = [$type, true, "declared in etc/cache.xml, so the install must enable it: $type"];
        }
        $rows[] = ['config', false, 'core cache type the module does not declare: config'];
        $rows[] = ['full_page', false, 'core cache type the module does not declare: full_page'];

        return $rows;
    }

    /**
     * @dataProvider cacheTypeProvider
     */
    public function testInstallEnablesEveryDeclaredCacheType(string $type, bool $expected, string $case): void
    {
        // Given the patch as the install and upgrade paths run it
        $enabled = [];
        $cacheManager = $this->createMock(CacheManager::class);
        $cacheManager->method('setEnabled')
            ->willReturnCallback(function (array $types, $isEnabled) use (&$enabled) {
                if ($isEnabled) {
                    $enabled = array_merge($enabled, $types);
                }
                return $types;
            });
        $patch = new EnableGatewayCacheType($cacheManager);
        self::assertInstanceOf(DataPatchInterface::class, $patch, "not reached by setup:upgrade: $case");

        // When it applies
        $patch->apply();

        // Then
        self::assertSame($expected, in_array($type, $enabled, true), $case);
    }

    /**
     * @return array<int, string>
     */
    private static function typesDeclaredInCacheXml(): array
    {
        $xml = simplexml_load_file(__DIR__ . '/../../../../../etc/cache.xml');
        self::assertNotFalse($xml, 'etc/cache.xml is unreadable');

        $types = [];
        foreach ($xml->type as $type) {
            $types[] = (string)$type['name'];
        }
        self::assertNotEmpty($types, 'etc/cache.xml declares no cache type');

        return $types;
    }
}
