<?php
declare(strict_types=1);

/**
 * Faithful stub of the dynamic-row admin field surface, so
 * Block/Adminhtml/System/Config/Field/CustomHeaders can be driven outside the
 * Magento framework.
 *
 * addColumn/getColumns/getArrayRows/renderCellTemplate are transcribed from
 * Magento\Config\Block\System\Config\Form\Field\FieldArray\AbstractFieldArray
 * (2.4.6), because what the tests assert is the markup and row data OUR
 * subclass and its cell renderer contribute through them. Only _toHtml is
 * substituted, the real one needing a template engine.
 *
 * Loads AFTER AdminConfigField.php, whose Field class this extends.
 */

namespace Magento\Framework\View\Element {
    if (!class_exists(AbstractBlock::class, false)) {
        class AbstractBlock extends \Magento\Framework\DataObject
        {
            public function toHtml()
            {
                return $this->_toHtml();
            }

            protected function _toHtml()
            {
                return '';
            }
        }
    }
}

namespace Magento\Config\Block\System\Config\Form\Field\FieldArray {
    use Magento\Framework\Data\Form\Element\AbstractElement;
    use Magento\Framework\DataObject;

    if (!class_exists(AbstractFieldArray::class, false)) {
        abstract class AbstractFieldArray extends \Magento\Config\Block\System\Config\Form\Field
        {
            /** @var array */
            protected $_columns = [];

            /** @var bool */
            protected $_addAfter = true;

            /** @var string */
            protected $_addButtonLabel;

            /** @var bool */
            protected $_isPreparedToRender = false;

            /** @var array|null */
            private $_arrayRowsCache;

            /** @var mixed the layout double a test injects with setLayout() */
            private $layout;

            /** @var AbstractElement|null */
            private $element;

            public function setLayout($layout): void
            {
                $this->layout = $layout;
            }

            public function getLayout()
            {
                return $this->layout;
            }

            public function setElement(AbstractElement $element): void
            {
                $this->element = $element;
            }

            public function getElement()
            {
                return $this->element;
            }

            public function addColumn($name, $params)
            {
                $this->_columns[$name] = [
                    'label' => $this->_getParam($params, 'label', 'Column'),
                    'size' => $this->_getParam($params, 'size', false),
                    'style' => $this->_getParam($params, 'style'),
                    'class' => $this->_getParam($params, 'class'),
                    'renderer' => false,
                ];
                if (!empty($params['renderer'])
                    && $params['renderer'] instanceof \Magento\Framework\View\Element\AbstractBlock
                ) {
                    $this->_columns[$name]['renderer'] = $params['renderer'];
                }
            }

            protected function _getParam($params, $paramName, $defaultValue = null)
            {
                return empty($params[$paramName]) ? $defaultValue : $params[$paramName];
            }

            public function getColumns()
            {
                return $this->_columns;
            }

            public function isAddAfter()
            {
                return $this->_addAfter;
            }

            public function getAddButtonLabel()
            {
                return $this->_addButtonLabel;
            }

            public function getArrayRows()
            {
                if (null !== $this->_arrayRowsCache) {
                    return $this->_arrayRowsCache;
                }
                $result = [];
                $element = $this->getElement();
                if ($element->getValue() && is_array($element->getValue())) {
                    foreach ($element->getValue() as $rowId => $row) {
                        $rowColumnValues = [];
                        foreach ($row as $key => $value) {
                            $row[$key] = $value;
                            $rowColumnValues[$this->_getCellInputElementId($rowId, $key)] = $row[$key];
                        }
                        $row['_id'] = $rowId;
                        $row['column_values'] = $rowColumnValues;
                        $result[$rowId] = new DataObject($row);
                        $this->_prepareArrayRow($result[$rowId]);
                    }
                }
                $this->_arrayRowsCache = $result;

                return $this->_arrayRowsCache;
            }

            protected function _prepareArrayRow(DataObject $row)
            {
            }

            protected function _getCellInputElementId($rowId, $columnName)
            {
                return $rowId . '_' . $columnName;
            }

            protected function _getCellInputElementName($columnName)
            {
                return $this->getElement()->getName() . '[<%- _id %>][' . $columnName . ']';
            }

            public function renderCellTemplate($columnName)
            {
                if (empty($this->_columns[$columnName])) {
                    throw new \Exception('Wrong column name specified.');
                }
                $column = $this->_columns[$columnName];
                $inputName = $this->_getCellInputElementName($columnName);

                if ($column['renderer']) {
                    return $column['renderer']->setInputName(
                        $inputName
                    )->setInputId(
                        $this->_getCellInputElementId('<%- _id %>', $columnName)
                    )->setColumnName(
                        $columnName
                    )->setColumn(
                        $column
                    )->toHtml();
                }

                return '<input type="text" id="' . $this->_getCellInputElementId('<%- _id %>', $columnName) . '"'
                    . ' name="' . $inputName . '" value="<%- ' . $columnName . ' %>" '
                    . ($column['size'] ? 'size="' . $column['size'] . '"' : '')
                    . ' class="' . ($column['class'] ?? 'input-text') . '"'
                    . (isset($column['style']) ? ' style="' . $column['style'] . '"' : '') . '/>';
            }

            protected function _prepareToRender()
            {
            }

            protected function _getElementHtml(AbstractElement $element)
            {
                $this->setElement($element);
                $html = $this->_toHtml();
                // Core resets here, the block being a layout singleton shared
                // by every field that names it as its frontend model.
                $this->_arrayRowsCache = null;

                return $html;
            }

            /**
             * The real one renders array.phtml. Preparing the columns is the
             * part a subclass contributes, so that is what the stub keeps.
             * The rendered rows are read back through getArrayRows().
             */
            protected function _toHtml()
            {
                if (!$this->_isPreparedToRender) {
                    $this->_prepareToRender();
                    $this->_isPreparedToRender = true;
                }
                if (empty($this->_columns)) {
                    throw new \Exception('At least one column must be defined.');
                }

                return 'field-array-html';
            }
        }
    }
}
