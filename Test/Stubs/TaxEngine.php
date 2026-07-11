<?php
/**
 * Stubs for Magento's tax rules engine API surface, used by the
 * SurchargeTaxCalculator tests.
 *
 * Signatures mirror magento/magento2 2.4-develop:
 *   Magento\Tax\Api\TaxCalculationInterface::calculateTax(
 *       QuoteDetailsInterface $quoteDetails, $storeId = null, $round = true)
 *   Magento\Tax\Api\Data\TaxClassKeyInterface::TYPE_ID / TYPE_NAME
 *
 * The data objects (QuoteDetails, QuoteDetailsItem, TaxClassKey,
 * TaxDetails, TaxDetailsItem) are DataObject-backed so fluent
 * setters/getters resolve via __call, exactly like Magento's own
 * AbstractExtensibleObject-generated models behave for these fields.
 * The *InterfaceFactory classes mirror Magento's generated factories.
 *
 * Must be required BEFORE the catch-all autoloader in bootstrap.php.
 */
declare(strict_types=1);

namespace Magento\Tax\Api {
    if (!interface_exists(TaxCalculationInterface::class, false)) {
        interface TaxCalculationInterface
        {
            /**
             * @param \Magento\Tax\Api\Data\QuoteDetailsInterface $quoteDetails
             * @param null|int $storeId
             * @param bool $round
             * @return \Magento\Tax\Api\Data\TaxDetailsInterface
             */
            public function calculateTax($quoteDetails, $storeId = null, $round = true);
        }
    }
    if (!interface_exists(TaxClassRepositoryInterface::class, false)) {
        interface TaxClassRepositoryInterface
        {
            /**
             * @param int $taxClassId
             * @return \Magento\Tax\Api\Data\TaxClassInterface
             */
            public function get($taxClassId);
        }
    }
    if (!interface_exists(TaxClassManagementInterface::class, false)) {
        interface TaxClassManagementInterface
        {
            public const TYPE_CUSTOMER = 'CUSTOMER';
            public const TYPE_PRODUCT = 'PRODUCT';
        }
    }
}

namespace Magento\Tax\Api\Data {
    if (!interface_exists(TaxClassKeyInterface::class, false)) {
        interface TaxClassKeyInterface
        {
            public const TYPE_ID = 'id';
            public const TYPE_NAME = 'name';
        }
    }
    if (!interface_exists(QuoteDetailsInterface::class, false)) {
        interface QuoteDetailsInterface
        {
        }
    }
    if (!interface_exists(QuoteDetailsItemInterface::class, false)) {
        interface QuoteDetailsItemInterface
        {
        }
    }
    if (!interface_exists(TaxDetailsInterface::class, false)) {
        interface TaxDetailsInterface
        {
        }
    }
    if (!interface_exists(TaxDetailsItemInterface::class, false)) {
        interface TaxDetailsItemInterface
        {
        }
    }
    if (!interface_exists(TaxClassInterface::class, false)) {
        interface TaxClassInterface
        {
        }
    }

    if (!class_exists(QuoteDetails::class, false)) {
        class QuoteDetails extends \Magento\Framework\DataObject implements QuoteDetailsInterface
        {
        }
        class QuoteDetailsItem extends \Magento\Framework\DataObject implements QuoteDetailsItemInterface
        {
        }
        class TaxClassKey extends \Magento\Framework\DataObject implements TaxClassKeyInterface
        {
        }
        class TaxDetails extends \Magento\Framework\DataObject implements TaxDetailsInterface
        {
        }
        class TaxDetailsItem extends \Magento\Framework\DataObject implements TaxDetailsItemInterface
        {
        }
        class TaxClass extends \Magento\Framework\DataObject implements TaxClassInterface
        {
        }
    }

    if (!class_exists(QuoteDetailsInterfaceFactory::class, false)) {
        class QuoteDetailsInterfaceFactory
        {
            public function create(array $data = [])
            {
                return new QuoteDetails($data['data'] ?? []);
            }
        }
        class QuoteDetailsItemInterfaceFactory
        {
            public function create(array $data = [])
            {
                return new QuoteDetailsItem($data['data'] ?? []);
            }
        }
        class TaxClassKeyInterfaceFactory
        {
            public function create(array $data = [])
            {
                return new TaxClassKey($data['data'] ?? []);
            }
        }
    }
}

namespace Magento\Customer\Api\Data {
    if (!interface_exists(AddressInterface::class, false)) {
        interface AddressInterface
        {
        }
    }
    if (!class_exists(AddressInterfaceFactory::class, false)) {
        // Underscore-mapped: the calculator passes snake_case data keys
        // ('country_id', 'region_id'), read back via getCountryId() etc.
        class Address extends \Two\Gateway\Test\Stubs\UnderscoreDataObject implements AddressInterface
        {
        }
        class Region extends \Two\Gateway\Test\Stubs\UnderscoreDataObject
        {
        }
        class AddressInterfaceFactory
        {
            public function create(array $arguments = [])
            {
                return new Address($arguments['data'] ?? []);
            }
        }
        class RegionInterfaceFactory
        {
            public function create(array $arguments = [])
            {
                return new Region($arguments['data'] ?? []);
            }
        }
    }
}

namespace Magento\Framework\Exception {
    if (!class_exists(NoSuchEntityException::class, false)) {
        class NoSuchEntityException extends LocalizedException
        {
            public function __construct(?\Magento\Framework\Phrase $phrase = null, ?\Exception $cause = null)
            {
                parent::__construct($phrase ?? new \Magento\Framework\Phrase('No such entity.'), $cause);
            }
        }
    }
}
