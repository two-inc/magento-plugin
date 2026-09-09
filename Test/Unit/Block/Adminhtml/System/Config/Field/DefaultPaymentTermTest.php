<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Block\Adminhtml\System\Config\Field;

use Magento\Backend\Block\Template\Context;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Block\Adminhtml\System\Config\Field\DefaultPaymentTerm;
use Two\Gateway\Service\Merchant\SettingsProvider;

/**
 * The API-supplied pre-selection is read for the scope being edited, so a store view is offered
 * its own record's terms rather than the default record's (ABN-522).
 */
class DefaultPaymentTermTest extends TestCase
{
    /** @param array<string, string> $params the admin page's own request params */
    private function block(SettingsProvider $settingsProvider, array $params): DefaultPaymentTerm
    {
        $request = $this->createMock(RequestInterface::class);
        $request->method('getParam')->willReturnCallback(static fn ($key) => $params[$key] ?? null);
        $context = $this->createMock(Context::class);
        $context->method('getRequest')->willReturn($request);

        $store = $this->createMock(StoreInterface::class);
        $store->method('getId')->willReturn(5);
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturnCallback(
            static fn ($code) => $code === 'broken' ? throw new \RuntimeException('no such store') : $store
        );

        return new class ($context, $settingsProvider, $storeManager) extends DefaultPaymentTerm {
            public function renderForTest(AbstractElement $element): string
            {
                return $this->_getElementHtml($element);
            }
        };
    }

    /**
     * @param array<string, string> $params
     * @dataProvider scopeProvider
     */
    public function testTheRecordIsReadForTheScopeBeingEdited(
        array $params,
        ?int $expectedStoreId,
        string $case
    ): void {
        $settingsProvider = $this->createMock(SettingsProvider::class);
        $settingsProvider->expects($this->once())
            ->method('getAvailableTerms')
            ->with($expectedStoreId)
            ->willReturn([14, 30]);
        $settingsProvider->expects($this->once())
            ->method('getDefaultTerm')
            ->with($expectedStoreId)
            ->willReturn(30);

        $element = new AbstractElement(['value' => '']);

        $this->assertSame('element-html', $this->block($settingsProvider, $params)->renderForTest($element), $case);
        $this->assertSame('30', $element->getValue(), $case);
    }

    public static function scopeProvider(): array
    {
        return [
            [['store' => 'de'], 5, 'the store param names the store whose record is read'],
            [[], null, 'no param is the default scope'],
            [['website' => 'eu'], null, 'a website scope has no single store to read'],
            [['store' => ''], null, 'an empty param is not a scope'],
            [['store' => 'broken'], null, 'an unresolvable store falls back rather than throwing'],
        ];
    }

    /**
     * The form object never carries scope, so reading it resolved every scope to default and a
     * store view was pre-selected from the default record.
     */
    public function testTheFormObjectIsNotTheScopeSource(): void
    {
        $settingsProvider = $this->createMock(SettingsProvider::class);
        $settingsProvider->expects($this->once())->method('getAvailableTerms')->with(5)->willReturn([14]);
        $settingsProvider->method('getDefaultTerm')->willReturn(14);

        $form = new class {
            public function getScope(): string
            {
                return 'default';
            }

            public function getScopeId(): int
            {
                return 0;
            }
        };

        $this->block($settingsProvider, ['store' => 'de'])
            ->renderForTest(new AbstractElement(['value' => '', 'form' => $form]));
    }

    /** An explicit stored choice wins, so the record is never consulted for it. */
    public function testAStoredChoiceIsLeftAlone(): void
    {
        $settingsProvider = $this->createMock(SettingsProvider::class);
        $settingsProvider->expects($this->never())->method('getAvailableTerms');

        $element = new AbstractElement(['value' => '45']);
        $this->block($settingsProvider, ['store' => 'de'])->renderForTest($element);

        $this->assertSame('45', $element->getValue());
    }
}
