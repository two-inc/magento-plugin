<?php
declare(strict_types=1);

/**
 * Minimal Magento\Framework\View\Element\Template surface, so a storefront
 * block can be constructed in a unit test. The real class takes its
 * collaborators from the context and touches none of them in the
 * constructor, which is all a block under test needs; mocking the real
 * Context instead fails with "Cannot call constructor".
 */

namespace Magento\Framework\View\Element\Template {
    if (!class_exists(Context::class, false)) {
        class Context
        {
        }
    }
}

namespace Magento\Framework\View\Element {
    if (!class_exists(Template::class, false)) {
        class Template extends \Magento\Framework\DataObject
        {
            public function __construct($context = null, array $data = [])
            {
                parent::__construct($data);
            }

            /** As core: the block escapes through its injected escaper. */
            public function escapeHtml($data, $allowedTags = null)
            {
                return htmlspecialchars((string)$data, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            }
        }
    }
}
