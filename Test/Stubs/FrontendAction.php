<?php
/**
 * Storefront action base with the members the plugin's return controllers
 * use (message manager, URL builder, response), so their failure handling can
 * be exercised. Per-symbol guard below.
 */
declare(strict_types=1);

namespace Magento\Framework\App\Action {
    if (!class_exists(Action::class, false)) {
        abstract class Action
        {
            /** @var \Magento\Framework\Message\ManagerInterface */
            protected $messageManager;

            /** @var object URL builder */
            protected $_url;

            /** @var object response */
            protected $_response;

            public function __construct($context)
            {
            }

            public function getResponse()
            {
                return $this->_response;
            }
        }
    }
}
