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
            /**
             * As core: the rendered id is the form's prefix and suffix around the element's own,
             * escaped. A form is always bound in production, so absent one both are empty.
             */
            public function getHtmlId()
            {
                $form = $this->getData('form');

                return htmlspecialchars(
                    ($form ? (string)$form->getHtmlIdPrefix() : '')
                    . (string)$this->getData('html_id')
                    . ($form ? (string)$form->getHtmlIdSuffix() : ''),
                    ENT_QUOTES | ENT_SUBSTITUTE,
                    'UTF-8'
                );
            }
        }
    }
}

namespace Magento\Backend\Block\Template {
    if (!class_exists(Context::class, false)) {
        class Context
        {
            /** As core: the block takes its request from the context, not from a setter. */
            public function getRequest()
            {
                return null;
            }
        }
    }
}

namespace Magento\Framework {
    if (!class_exists(Escaper::class, false)) {
        class Escaper
        {
            /**
             * @param string $data
             * @param array|null $allowedTags
             * @return string
             */
            public function escapeHtml($data, $allowedTags = null)
            {
                return htmlspecialchars((string)$data, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            }
        }
    }
}

namespace Magento\Config\Model\Config\Reader\Source\Deployed {
    if (!class_exists(SettingChecker::class, false)) {
        class SettingChecker
        {
            /**
             * @param string $path
             * @param string $scope
             * @param string|null $scopeCode
             * @return bool
             */
            public function isReadOnly($path, $scope, $scopeCode = null)
            {
                return false;
            }
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

            public function getRequest()
            {
                return $this->context->getRequest();
            }

            /**
             * As core, whose Field descends from DataObject: renderers stash the element on
             * themselves, and an array key replaces the whole bag rather than indexing it.
             */
            public function setData($key, $value = null)
            {
                if ($key === (array)$key) {
                    $this->data = $key;
                } else {
                    $this->data[(string)$key] = $value;
                }

                return $this;
            }

            public function getData($key = '', $index = null)
            {
                return $key === '' ? $this->data : ($this->data[$key] ?? null);
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

            /**
             * @param string $data
             * @param array|null $allowedTags
             * @return string
             */
            public function escapeHtml($data, $allowedTags = null)
            {
                return htmlspecialchars((string)$data, ENT_QUOTES, 'UTF-8');
            }

            /**
             * Laminas' rule, which the real Escaper delegates to: everything outside a
             * conservative alphanumeric set becomes a numeric entity, brackets included.
             *
             * @param string $string
             * @param bool $escapeSingleQuote
             * @return string
             */
            public function escapeHtmlAttr($string, $escapeSingleQuote = true)
            {
                return preg_replace_callback(
                    '/[^a-zA-Z0-9,\.\-_]/u',
                    static function (array $match): string {
                        return sprintf('&#x%02X;', mb_ord($match[0], 'UTF-8'));
                    },
                    (string)$string
                );
            }
        }
    }
}
