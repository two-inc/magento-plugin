<?php
declare(strict_types=1);

/**
 * Minimal stubs for the admin system-config field renderer surface, so
 * Block/Adminhtml/System/Config/Field/* can be instantiated and their real
 * _getElementHtml() bodies exercised outside the Magento framework.
 *
 * Deliberately real classes rather than the bootstrap's method-less catch-all:
 * a renderer's whole job is what it does to the element on the way through, so
 * the base class has to accept the constructor call and the element has to
 * carry working getValue()/setComment() accessors.
 */

namespace Magento\Framework\Data\Form\Element {
    if (!class_exists(AbstractElement::class, false)) {
        class AbstractElement extends \Magento\Framework\DataObject
        {
        }
    }
}

namespace Magento\Backend\Block\Template {
    if (!class_exists(Context::class, false)) {
        class Context
        {
        }
    }
}

namespace Magento\Config\Block\System\Config\Form {
    use Magento\Backend\Block\Template\Context;
    use Magento\Framework\Data\Form\Element\AbstractElement;

    if (!class_exists(Field::class, false)) {
        class Field
        {
            /** @var Context */
            protected $context;

            /** @var array */
            protected $data;

            /** @var mixed the config Form block, bound via setForm() at render time */
            private $form;

            public function __construct(Context $context, array $data = [])
            {
                $this->context = $context;
                $this->data = $data;
            }

            public function setForm($form): void
            {
                $this->form = $form;
            }

            public function getForm()
            {
                return $this->form;
            }

            /**
             * Base rendering turns the element into markup; the stub returns a
             * marker so a subclass's own contribution is distinguishable.
             *
             * NO return type, matching core: a child may narrow to `: string`
             * (Block\Adminhtml\System\Config\Field\CustomSurchargeTaxRate
             * does) but declaring one here would break every child that does
             * not (Block\Adminhtml\System\Config\Field\Version).
             */
            protected function _getElementHtml(AbstractElement $element)
            {
                return 'element-html';
            }

            /**
             * @param string $route
             * @param array $params
             * @return string
             */
            public function getUrl($route = '', $params = [])
            {
                return 'https://admin.example/' . $route;
            }
        }
    }
}
