<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Model\Config\Source;

use PHPUnit\Framework\TestCase;
use Two\Gateway\Model\Config\Source\AvailablePaymentTerms;
use Two\Gateway\Service\Merchant\SettingsProvider;

/**
 * The empty option is what lets an admin leave the default term to the
 * checkout's resolver; without it the select can only name a day count
 * and posts one on every save (ABN-548).
 */
class AvailablePaymentTermsTest extends TestCase
{
    /**
     * @param int[] $offered
     * @param array<int, string> $expected
     * @dataProvider optionProvider
     */
    public function testTheOptionsOffered(array $offered, array $expected, string $case): void
    {
        $settingsProvider = $this->createMock(SettingsProvider::class);
        $settingsProvider->method('getAvailableTerms')->willReturn($offered);

        $source = new AvailablePaymentTerms($settingsProvider);
        $values = array_map('strval', array_column($source->toOptionArray(), 'value'));

        $this->assertSame($expected, $values, $case);
    }

    public static function optionProvider(): array
    {
        return [
            [[7, 30], ['', '7', '30'], 'the empty option comes first, ahead of every offered term'],
            [[], [''], 'nothing offered still offers the empty option'],
        ];
    }
}
