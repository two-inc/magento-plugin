<?php
/**
 * Sales-document total-collection stub.
 *
 * Magento's creditmemo AbstractTotal descends from DataObject, so a collector
 * with its own constructor calls parent::__construct($data). The catch-all
 * autoloader's method-less class would fatal on that call, so declare it
 * extending DataObject here.
 *
 * Must be required BEFORE the catch-all autoloader in bootstrap.php.
 */
declare(strict_types=1);

namespace Magento\Sales\Model\Order\Creditmemo\Total {
    if (!class_exists(AbstractTotal::class, false)) {
        abstract class AbstractTotal extends \Magento\Framework\DataObject
        {
        }
    }
}
