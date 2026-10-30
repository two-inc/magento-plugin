<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Model\Config\Backend;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\Model\Context;
use Magento\Framework\Registry;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\BrandRegistryInterface;
use Two\Gateway\Model\Config\Backend\TaxCodeMap;
use Two\Gateway\Model\Config\TaxCodeSectionResolver;

/**
 * TWO-26153: what a save of "Tax codes for 0% lines" stores, by what the section shows.
 */
class TaxCodeMapTest extends TestCase
{
    private const STORED = '{"5|none":"ES_IVA_EXPORT","5|exempt":"ES_IVA_RETIRED"}';

    private const CODES = [
        ['code' => 'ES_IVA_EXPORT', 'name' => '', 'rate' => '0'],
        ['code' => 'ES_IVA_INTRA', 'name' => '', 'rate' => '0'],
        ['code' => 'ES_IVA_STANDARD', 'name' => '', 'rate' => '0.21'],
    ];

    /**
     * @dataProvider saves
     * @param mixed $posted
     * @param string|null $stored what is stored; null when nothing is
     * @param string $message the admin message's level, '' for none
     */
    public function testSave(string $view, $posted, ?string $stored, string $message, string $description): void
    {
        $state = ['view' => $view, 'notices' => [], 'clear' => false, 'country' => 'ES', 'codes' => self::CODES,
            'rejected' => false, 'http' => $view === 'list_error' ? 503 : 0];
        $resolver = $this->createMock(TaxCodeSectionResolver::class);
        $resolver->method('resolve')->willReturn($state);
        $messages = $this->createMock(ManagerInterface::class);
        foreach (['error' => 'addErrorMessage', 'warning' => 'addWarningMessage'] as $level => $method) {
            $messages->expects($message === $level ? $this->once() : $this->never())->method($method);
        }
        $config = $this->createMock(ScopeConfigInterface::class);
        $config->method('getValue')->willReturn(self::STORED);
        $brand = $this->createMock(BrandRegistryInterface::class);
        $brand->method('getProductName')->willReturn('Two');
        $value = new TaxCodeMap(
            $this->getMockBuilder(Context::class)->disableOriginalConstructor()->getMock(),
            $this->getMockBuilder(Registry::class)->disableOriginalConstructor()->getMock(),
            $config,
            $this->createMock(TypeListInterface::class),
            $resolver, $messages, $brand, null, null, [
            'value' => $posted, 'path' => 'payment/two_payment/tax_code_map', 'scope' => 'default', 'scope_id' => 0,
        ]);
        if ($stored === 'refused') {
            $this->expectException(LocalizedException::class);
        }
        $value->beforeSave();

        $this->assertSame($stored, $value->isSaveAllowed() ? $value->getValue() : null, $description);
    }

    public static function saves(): array
    {
        return [
            ['rows', '{"5|none":"ES_IVA_INTRA"}', '{"5|none":"ES_IVA_INTRA"}', '', 'an offered code is stored'],
            ['rows', self::STORED, '{"5|exempt":"ES_IVA_RETIRED","5|none":"ES_IVA_EXPORT"}', '', 'an unchanged stale code is kept'],
            ['rows', '{"5|none":"ES_IVA_STANDARD"}', null, 'error', 'a changed row holding a code not offered at 0% is not saved'],
            ['rows', '{"5|none":"ES_IVA_GONE"}', null, 'error', 'a changed row holding an unlisted code is not saved'],
            ['rows', '{}', '', '', 'every row on (none) stores nothing'],
            ['rows', '', '', '', 'an emptied field stores nothing'],
            ['rows', '{"5|none":"ES_IVA_EXP', 'refused', '', 'a cut-off post is refused'],
            ['rows', '"5|none"', 'refused', '', 'JSON that is not an object is refused'],
            ['rows', '[]', 'refused', '', 'a JSON list is refused'],
            ['rows', ['5' => 'ES_IVA_EXPORT'], 'refused', '', 'a form posting one field per class is refused'],
            ['rows', null, 'refused', '', 'no value is refused'],
            ['hidden', '{"5|none":"ES_IVA_INTRA"}', null, '', 'hidden: posted rows change nothing and raise nothing'],
            ['notice', '{"5|none":"ES_IVA_EXP', null, '', 'hidden rows cut short change nothing and raise nothing'],
            ['notice', TaxCodeMap::CLEAR, '{}', '', 'Clear stores an empty map'],
            ['rows', TaxCodeMap::CLEAR, '{}', '', 'Clear wins over the rows'],
            ['hidden', TaxCodeMap::CLEAR, '{}', '', 'Clear is honoured however the section shows'],
            ['list_error', '{"5|none":"ES_IVA_INTRA"}', null, 'warning', 'an edit the list could not check is not saved, and says so'],
            ['profile_error', '{"5|none":"ES_IVA_EXP', null, 'warning', 'a cut-off post while the record is unreadable says so'],
            ['list_error', self::STORED, null, '', 'no edit, nothing to say'],
        ];
    }
}
