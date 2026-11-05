<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Model\Config\Backend;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Message\ManagerInterface as MessageManager;
use Magento\Framework\Model\Context;
use Magento\Framework\Registry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Model\Config\Backend\VendorName;

/**
 * TWO-27108: the vendor name is printable ASCII of at most 255 characters,
 * checked on save; a refused name keeps the stored one, and only that field.
 */
class VendorNameTest extends TestCase
{
    public static function names(): array
    {
        return [
            // submitted, saved, value written, description
            'blank' => ['', true, '', 'no vendor name is allowed'],
            'plain' => ['North Warehouse', true, 'North Warehouse', 'printable ASCII saves'],
            'trimmed' => ['  Shop B  ', true, 'Shop B', 'surrounding spaces are trimmed'],
            'punctuation' => ['A&B (EU) #2', true, 'A&B (EU) #2', 'ASCII punctuation is printable'],
            'at the limit' => [str_repeat('a', 255), true, str_repeat('a', 255), '255 characters is the limit'],
            'over the limit' => [str_repeat('a', 256), false, null, '256 characters is refused'],
            'accented' => ['Café Nord', false, null, 'an accented letter is refused'],
            'line break' => ["Shop\r\nB", false, null, 'a line break is refused'],
            'tab' => ["Shop\tB", false, null, 'a control character is refused'],
            'unchanged old name' => ['Café Nord', true, 'Café Nord', 'a name saved before the rule, posted back unchanged, still saves', 'Café Nord'],
            'changed old name' => ['Café Sud', false, null, 'changing a name saved before the rule applies the rule', 'Café Nord'],
        ];
    }

    private function config(?string $stored): ScopeConfigInterface
    {
        $config = $this->createMock(ScopeConfigInterface::class);
        $config->method('getValue')->willReturn($stored);

        return $config;
    }

    #[DataProvider('names')]
    public function testTheVendorNameIsCheckedOnSave(
        string $submitted,
        bool $saved,
        ?string $written,
        string $description,
        ?string $stored = null
    ): void {
        $messages = $this->createMock(MessageManager::class);
        $messages->expects($saved ? $this->never() : $this->once())->method('addErrorMessage');
        $model = new VendorName(
            $this->getMockBuilder(Context::class)->disableOriginalConstructor()->getMock(),
            $this->getMockBuilder(Registry::class)->disableOriginalConstructor()->getMock(),
            $this->config($stored),
            $this->createMock(TypeListInterface::class),
            $messages,
            null,
            null,
            ['value' => $submitted, 'scope' => 'default', 'scope_id' => 0]
        );

        $model->beforeSave();

        $this->assertSame($saved, $model->isSaveAllowed(), $description);
        if ($saved) {
            $this->assertSame($written, $model->getValue(), $description);
        }
    }
}
