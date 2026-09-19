<?php
/**
 * Quote total-collection stubs for testing Model\Total\Surcharge.
 *
 * AbstractTotal must declare collect()/fetch() with the same parameter
 * types the plugin's collector uses — PHP forbids a subclass adding
 * parameter types over an untyped parent, so an empty catch-all stub
 * would fatal on class load. Total and the checkout Session are
 * DataObject-style magic bags, matching how Magento's real classes
 * behave for the fields the collector touches.
 *
 * Must be required BEFORE the catch-all autoloader in bootstrap.php.
 */
declare(strict_types=1);

namespace Magento\Quote\Model\Quote\Address {
    if (!class_exists(Total::class, false)) {
        class Total extends \Two\Gateway\Test\Stubs\UnderscoreDataObject
        {
        }
    }
}

namespace Magento\Quote\Model\Quote\Address\Total {
    if (!class_exists(AbstractTotal::class, false)) {
        abstract class AbstractTotal
        {
            /** @var string */
            protected $_code;

            public function setCode($code)
            {
                $this->_code = $code;
                return $this;
            }

            public function getCode()
            {
                return $this->_code;
            }

            public function collect(
                \Magento\Quote\Model\Quote $quote,
                \Magento\Quote\Api\Data\ShippingAssignmentInterface $shippingAssignment,
                \Magento\Quote\Model\Quote\Address\Total $total
            ) {
                return $this;
            }

            public function fetch(
                \Magento\Quote\Model\Quote $quote,
                \Magento\Quote\Model\Quote\Address\Total $total
            ) {
                return [];
            }
        }
    }
}

namespace Magento\Checkout\Model {
    if (!class_exists(Session::class, false)) {
        /**
         * Checkout session stub: magic get/set like the real session's
         * storage passthrough (getTwoSurchargeAmount() etc).
         */
        class Session extends \Two\Gateway\Test\Stubs\UnderscoreDataObject
        {
            /**
             * TWO-25800: the express confirmation asks the session's quote what
             * the attempt did. Declared explicitly because the magic
             * passthrough above answers get/set, not this, and because a mock
             * can only configure a method its class declares — so it belongs on
             * the SHARED stub rather than a narrower competing one.
             */
            public function getQuote()
            {
                // Defers to whatever a test `set` first: the magic passthrough
                // this class extends answers get/set, and declaring a real
                // method shadows it, so a test that did setQuote() would
                // otherwise be handed a different object than the one it built.
                return $this->getData('quote') ?: new \Magento\Quote\Model\Quote();
            }
        }
    }
}
