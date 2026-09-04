<?php
declare(strict_types=1);

/**
 * Minimal stub of the payment-method base class Model\Two extends, so
 * Two::isAvailable() can be exercised outside the Magento framework.
 *
 * Deliberately a real class rather than the bootstrap's method-less
 * catch-all, for two reasons:
 *
 * 1. isAvailable() chains to `parent::isAvailable()` (core's active-flag
 *    check). Against a method-less stub that call is fatal, so the
 *    availability gates in Two::isAvailable() would be untestable.
 * 2. `$_scopeConfig` is declared by the base class in core, not by Two.
 *    Declaring it here gives tests a real property to inject into
 *    instead of relying on a dynamic property.
 */

namespace Magento\Payment\Model\Method {
    if (!class_exists(AbstractMethod::class, false)) {
        class AbstractMethod
        {
            /** @var string */
            protected $_code;

            /** @var \Magento\Framework\App\Config\ScopeConfigInterface */
            protected $_scopeConfig;

            /**
             * Stands in for core's own availability verdict (method active,
             * currency supported, …). Tests set it to exercise both the
             * "core says no, so Two must not even look further" path and the
             * normal path where Two's own gates decide.
             *
             * @var bool
             */
            protected $stubAvailableInBase = true;

            /**
             * Signature matches core's, so Model\Two's own narrower
             * declaration stays compatible with it.
             *
             * @return bool
             */
            public function isAvailable(?\Magento\Quote\Api\Data\CartInterface $quote = null)
            {
                return $this->stubAvailableInBase;
            }

            /**
             * Admin payment config, keyed by field name.
             *
             * @var array<string,mixed>
             */
            protected $stubConfigData = [];

            /** @var mixed */
            protected $stubStore = null;

            public function getConfigData($field, $storeId = null)
            {
                return $this->stubConfigData[$field] ?? null;
            }

            public function getStore()
            {
                return $this->stubStore;
            }

            /**
             * Core's allowspecific/specificcountry gate, reproduced so
             * Model\Two::canUseForCountry() is tested against real core
             * behaviour rather than a stub that always concedes.
             *
             * @return bool
             */
            public function canUseForCountry($country)
            {
                if ($this->getConfigData('allowspecific') == 1) {
                    $availableCountries = explode(',', (string)$this->getConfigData('specificcountry'));
                    if (!in_array($country, $availableCountries)) {
                        return false;
                    }
                }
                return true;
            }
        }
    }
}
