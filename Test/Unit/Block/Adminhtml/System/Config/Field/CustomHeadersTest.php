<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Block\Adminhtml\System\Config\Field;

use Magento\Backend\Block\Template\Context;
use Magento\Framework\Data\Form\Element\AbstractElement;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Block\Adminhtml\System\Config\Field\CustomHeaderBrowserCheckbox;
use Two\Gateway\Block\Adminhtml\System\Config\Field\CustomHeaders;

/**
 * The admin grid for the custom-header table: its columns, and the row data
 * array.phtml renders each row's controls from.
 */
class CustomHeadersTest extends TestCase
{
    private function block(): CustomHeaders
    {
        $block = new CustomHeaders(new Context());

        $layout = new class {
            public function createBlock($class)
            {
                return new $class();
            }
        };
        $block->setLayout($layout);

        return $block;
    }

    /**
     * Drives the production render entry point, which is protected.
     *
     * @param mixed $value
     */
    private function render(CustomHeaders $block, $value): void
    {
        $method = new \ReflectionMethod($block, '_getElementHtml');
        $method->setAccessible(true);
        $method->invoke($block, $this->element($value));
    }

    /**
     * @param mixed $value
     */
    private function element($value): AbstractElement
    {
        $element = new AbstractElement();
        $element->setData('name', 'groups[admin_controls][fields][custom_headers][value]');
        $element->setData('value', $value);

        return $element;
    }

    public function testTheGridHasANameAValueAndABrowserTickColumn(): void
    {
        $block = $this->block();
        $this->render($block, []);

        $this->assertSame(
            ['name', 'value', 'send_from_browser'],
            array_keys($block->getColumns())
        );
        $this->assertSame(
            ['Header name', 'Header value', 'Also send from browser'],
            array_map(
                static fn(array $column) => (string)$column['label'],
                array_values($block->getColumns())
            )
        );
    }

    /**
     * "Add after" would let the admin insert a row mid-table; the stored order
     * carries no meaning, so the one Add button is the whole control.
     */
    public function testRowsAreOnlyAppended(): void
    {
        $block = $this->block();
        $this->render($block, []);

        $this->assertFalse($block->isAddAfter());
        $this->assertSame('Add header', (string)$block->getAddButtonLabel());
    }

    /**
     * Given a value core handed over without running the backend model (a
     * table locked by `app:config:dump`); When the field renders; Then the
     * grid still shows the configured rows.
     */
    public function testARawStoredStringStillRendersItsRows(): void
    {
        $block = $this->block();
        $this->render($block, '{"_1":{"name":"X-WAF-TOKEN","value":"abc","send_from_browser":"1"}}');

        $rows = $block->getArrayRows();
        $this->assertCount(1, $rows);
        $this->assertSame('X-WAF-TOKEN', $rows['_1']->getData('name'));
        $this->assertSame('1', $rows['_1']->getData('send_from_browser'));
    }

    /**
     * The tick of a stored row is applied by array.phtml handing each column
     * value to Prototype's setValue(), which reads any truthy string as
     * ticked — so an unticked row has to arrive as '' and not '0'.
     *
     * @dataProvider storedFlags
     */
    public function testTheRowsBrowserFlagIsOnlyEverOneOrEmpty(
        string $stored,
        string $expected,
        string $description
    ): void {
        $block = $this->block();
        $this->render(
            $block,
            '{"_1":{"name":"X-WAF-TOKEN","value":"abc","send_from_browser":"' . $stored . '"}}'
        );

        $columnValues = $block->getArrayRows()['_1']->getData('column_values');

        $this->assertSame($expected, $columnValues['_1_send_from_browser'], $description);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function storedFlags(): array
    {
        return [
            'ticked' => ['1', '1', 'a ticked row arrives truthy'],
            'unticked' => ['0', '', "'0' is a truthy string in JavaScript and would tick the box"],
            'never ticked' => ['', '', 'an unstored flag is off'],
        ];
    }

    /**
     * The cell markup goes into a client-side row template, so the row
     * placeholder has to survive verbatim and the box has to post a value.
     */
    public function testTheBrowserTickCellPostsOneUnderTheRowsOwnName(): void
    {
        $block = $this->block();
        $this->render($block, []);

        $cell = $block->renderCellTemplate('send_from_browser');

        $this->assertStringContainsString('type="checkbox"', $cell);
        $this->assertStringContainsString('value="1"', $cell);
        $this->assertStringContainsString(
            'name="groups[admin_controls][fields][custom_headers][value][<%- _id %>][send_from_browser]"',
            $cell,
            'an escaped placeholder would name every row the same'
        );
        $this->assertStringNotContainsString('admin__control-checkbox', $cell, 'that class hides the input');
    }

    public function testTheBrowserTickIsRenderedByItsOwnBlock(): void
    {
        $block = $this->block();
        $this->render($block, []);

        $this->assertInstanceOf(
            CustomHeaderBrowserCheckbox::class,
            $block->getColumns()['send_from_browser']['renderer']
        );
    }
}
