<?php
declare(strict_types=1);

/**
 * Real-constant Area stub and a faithful Magento\Payment\Block\Info stub
 * (mirrors vendor/magento/module-payment/Block/Info.php's
 * getSpecificInformation()/_prepareSpecificInformation() contract), so
 * Block\Payment\Info's admin-only row injection is exercised against the
 * same DataObject accumulation the real block does, not an empty
 * catch-all class.
 */

namespace Magento\Framework\App {
    if (!class_exists(Area::class, false)) {
        class Area
        {
            public const AREA_GLOBAL = 'global';
            public const AREA_FRONTEND = 'frontend';
            public const AREA_ADMINHTML = 'adminhtml';
            public const AREA_DOC = 'doc';
            public const AREA_CRONTAB = 'crontab';
            public const AREA_WEBAPI_REST = 'webapi_rest';
            public const AREA_WEBAPI_SOAP = 'webapi_soap';
            public const AREA_GRAPHQL = 'graphql';
        }
    }
}

namespace Magento\Payment\Block {
    if (!class_exists(Info::class, false)) {
        class Info extends \Magento\Framework\DataObject
        {
            /** @var \Magento\Framework\DataObject|null */
            protected $_paymentSpecificInformation;

            public function __construct($context = null, array $data = [])
            {
                parent::__construct($data);
            }

            public function getInfo()
            {
                return $this->getData('info');
            }

            public function getMethod()
            {
                return $this->getInfo()->getMethodInstance();
            }

            public function getSpecificInformation()
            {
                return $this->_prepareSpecificInformation()->getData();
            }

            protected function _prepareSpecificInformation($transport = null)
            {
                if (null === $this->_paymentSpecificInformation) {
                    if (null === $transport) {
                        $transport = new \Magento\Framework\DataObject();
                    } elseif (is_array($transport)) {
                        $transport = new \Magento\Framework\DataObject($transport);
                    }
                    $this->_paymentSpecificInformation = $transport;
                }
                return $this->_paymentSpecificInformation;
            }
        }
    }
}
