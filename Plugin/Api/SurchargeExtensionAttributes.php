<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Plugin\Api;

use Magento\Framework\Api\ExtensibleDataInterface;
use Magento\Framework\Api\SearchResults;
use Magento\Sales\Api\Data\CreditmemoExtensionFactory;
use Magento\Sales\Api\Data\CreditmemoInterface;
use Magento\Sales\Api\Data\InvoiceExtensionFactory;
use Magento\Sales\Api\Data\InvoiceInterface;
use Magento\Sales\Api\Data\OrderExtensionFactory;
use Magento\Sales\Api\Data\OrderInterface;

/**
 * Populates the Two surcharge fields onto the extension_attributes object
 * of every Order / Invoice / Creditmemo returned via the sales API
 * repositories. Without this plugin the columns exist on the underlying
 * row but are invisible to REST/GraphQL consumers (PWA, headless, external
 * reconciliation jobs).
 *
 * Wired against Magento\Sales\Api\OrderRepositoryInterface (and the invoice
 * + creditmemo equivalents) in etc/di.xml so it covers get / getList /
 * save round-trips.
 */
class SurchargeExtensionAttributes
{
    /** @var string[] Column → setter suffix (extension attribute name uses snake-case keys but the factory's set methods use camelCase). */
    private const ORDER_FIELDS = [
        'two_surcharge_amount',
        'base_two_surcharge_amount',
        'two_surcharge_tax_amount',
        'base_two_surcharge_tax_amount',
        'two_surcharge_description',
        'two_surcharge_tax_rate',
        'two_surcharge_invoiced',
        'base_two_surcharge_invoiced',
        'two_surcharge_refunded',
        'base_two_surcharge_refunded',
    ];

    private const INVOICE_FIELDS = [
        'two_surcharge_amount',
        'base_two_surcharge_amount',
        'two_surcharge_tax_amount',
        'base_two_surcharge_tax_amount',
        'two_surcharge_description',
        'two_surcharge_tax_rate',
    ];

    private const CREDITMEMO_FIELDS = self::INVOICE_FIELDS;

    /**
     * @var OrderExtensionFactory
     */
    private $orderExtensionFactory;

    /**
     * @var InvoiceExtensionFactory
     */
    private $invoiceExtensionFactory;

    /**
     * @var CreditmemoExtensionFactory
     */
    private $creditmemoExtensionFactory;

    public function __construct(
        OrderExtensionFactory $orderExtensionFactory,
        InvoiceExtensionFactory $invoiceExtensionFactory,
        CreditmemoExtensionFactory $creditmemoExtensionFactory
    ) {
        $this->orderExtensionFactory = $orderExtensionFactory;
        $this->invoiceExtensionFactory = $invoiceExtensionFactory;
        $this->creditmemoExtensionFactory = $creditmemoExtensionFactory;
    }

    public function afterGet($subject, $entity)
    {
        $this->populate($entity);
        return $entity;
    }

    public function afterGetList($subject, $result)
    {
        if ($result instanceof SearchResults || method_exists($result, 'getItems')) {
            foreach ($result->getItems() as $item) {
                $this->populate($item);
            }
        }
        return $result;
    }

    public function afterSave($subject, $entity)
    {
        $this->populate($entity);
        return $entity;
    }

    private function populate(ExtensibleDataInterface $entity): void
    {
        if ($entity instanceof OrderInterface) {
            $extension = $entity->getExtensionAttributes() ?: $this->orderExtensionFactory->create();
            $this->copyFields($entity, $extension, self::ORDER_FIELDS);
            $entity->setExtensionAttributes($extension);
            return;
        }
        if ($entity instanceof InvoiceInterface) {
            $extension = $entity->getExtensionAttributes() ?: $this->invoiceExtensionFactory->create();
            $this->copyFields($entity, $extension, self::INVOICE_FIELDS);
            $entity->setExtensionAttributes($extension);
            return;
        }
        if ($entity instanceof CreditmemoInterface) {
            $extension = $entity->getExtensionAttributes() ?: $this->creditmemoExtensionFactory->create();
            $this->copyFields($entity, $extension, self::CREDITMEMO_FIELDS);
            $entity->setExtensionAttributes($extension);
        }
    }

    /**
     * Copy each field from the entity (via the magic getter on the data
     * object) onto the extension attributes object (via its setter).
     */
    private function copyFields(ExtensibleDataInterface $entity, $extension, array $fields): void
    {
        foreach ($fields as $field) {
            $value = $entity->getData($field);
            if ($value === null) {
                continue;
            }
            $setter = 'set' . str_replace('_', '', ucwords($field, '_'));
            if (method_exists($extension, $setter)) {
                $extension->{$setter}($value);
            }
        }
    }
}
