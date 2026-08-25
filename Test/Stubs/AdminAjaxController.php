<?php
declare(strict_types=1);

/**
 * Minimal stubs for the admin AJAX controller surface (backend action base
 * class, its context, and the JSON result + factory) plus the encrypted
 * config backend model base class.
 *
 * Real classes rather than the bootstrap's method-less catch-all: the tests
 * configure create()/encrypt() on mocks and read back what setData()
 * received, none of which is possible against an empty stub.
 */

namespace Magento\Framework\Controller\Result {
    if (!class_exists(Json::class, false)) {
        class Json
        {
            /** @var mixed */
            private $data;

            public function setData($data)
            {
                $this->data = $data;
                return $this;
            }

            public function getData()
            {
                return $this->data;
            }
        }
    }
    if (!class_exists(JsonFactory::class, false)) {
        class JsonFactory
        {
            public function create(array $data = [])
            {
                return new Json();
            }
        }
    }
}

namespace Magento\Backend\App\Action {
    if (!class_exists(Context::class, false)) {
        class Context
        {
            public function getRequest()
            {
                return null;
            }
        }
    }
}

namespace Magento\Backend\App {
    if (!class_exists(Action::class, false)) {
        class Action
        {
            /** @var \Magento\Framework\App\RequestInterface|null */
            private $request;

            public function __construct(Action\Context $context)
            {
                $this->request = $context->getRequest();
            }

            public function getRequest()
            {
                return $this->request;
            }
        }
    }
}

namespace Magento\Framework\Encryption {
    if (!interface_exists(EncryptorInterface::class, false)) {
        interface EncryptorInterface
        {
            public function encrypt($data);

            public function decrypt($data);
        }
    }
}

namespace Magento\Config\Model\Config\Backend {
    use Magento\Framework\App\Config\ScopeConfigInterface;
    use Magento\Framework\Encryption\EncryptorInterface;

    if (!class_exists(Encrypted::class, false)) {
        /**
         * Mirrors the real class's beforeSave(): the submitted value is
         * encrypted unless it is the obscured all-asterisks placeholder or
         * empty, in which case the stored value is left alone.
         */
        class Encrypted extends \Magento\Framework\App\Config\Value
        {
            /** @var EncryptorInterface */
            protected $_encryptor;

            public function __construct(
                $context,
                $registry,
                ScopeConfigInterface $config,
                $cacheTypeList,
                EncryptorInterface $encryptor,
                $resource = null,
                $resourceCollection = null,
                array $data = []
            ) {
                parent::__construct($context, $registry, $config, $cacheTypeList, $resource, $resourceCollection, $data);
                $this->_encryptor = $encryptor;
            }

            public function beforeSave()
            {
                $value = (string)$this->getValue();
                if (!preg_match('/^\*+$/', $value) && $value !== '') {
                    $this->setValue($this->_encryptor->encrypt(trim($value)));
                }

                return parent::beforeSave();
            }
        }
    }
}
