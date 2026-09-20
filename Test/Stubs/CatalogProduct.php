<?php
/**
 * Minimal catalog-product surface, so Block/Product/ExpressButton can be built
 * and its saleability gate exercised in a unit test without the real catalog.
 *
 * PHPUnit will happily mock a class that does not exist, by generating an empty
 * one — and then every method the test configures is "a method that cannot be
 * configured because it does not exist". So the gate's own method has to be
 * declared somewhere, and this is where this suite declares Magento surface.
 */
declare(strict_types=1);

namespace Magento\Catalog\Api\Data {
    if (!interface_exists(ProductInterface::class, false)) {
        interface ProductInterface
        {
            public function getId();
        }
    }
}

namespace Magento\Catalog\Model {
    use Magento\Catalog\Api\Data\ProductInterface;

    if (!class_exists(Product::class, false)) {
        class Product implements ProductInterface
        {
            public function getId()
            {
                return 1;
            }

            /**
             * Real Magento resolves disabled, out of stock, and a configurable
             * whose children are all unavailable through this one call.
             */
            public function isSalable()
            {
                return true;
            }

            public function getTypeId()
            {
                return 'simple';
            }

            public function getProductUrl()
            {
                return '';
            }
        }
    }
}

namespace Magento\Framework {
    if (!class_exists(Registry::class, false)) {
        class Registry
        {
            /** @var array<string,mixed> */
            private $values = [];

            public function registry($key)
            {
                return $this->values[$key] ?? null;
            }

            public function register($key, $value, $graceful = false)
            {
                $this->values[$key] = $value;
            }
        }
    }
}

namespace Magento\Catalog\Api {
    if (!interface_exists(ProductRepositoryInterface::class, false)) {
        interface ProductRepositoryInterface
        {
            public function getById($productId);
        }
    }
}

namespace Magento\Framework\Controller\Result {
    if (!class_exists(Redirect::class, false)) {
        class Redirect
        {
            public function setPath($path, $params = [])
            {
                return $this;
            }

            public function setUrl($url)
            {
                return $this;
            }
        }
    }

    if (!class_exists(RedirectFactory::class, false)) {
        class RedirectFactory
        {
            public function create()
            {
                return new Redirect();
            }
        }
    }
}
