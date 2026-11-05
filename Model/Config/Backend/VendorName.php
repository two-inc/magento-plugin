<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Model\Config\Backend;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Value;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\Message\ManagerInterface as MessageManager;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Registry;

/**
 * The vendor name must be printable ASCII of at most 255 characters, the
 * rule every Two plugin applies (TWO-27108; some send it as a request header,
 * where anything else is a wire fault). This plugin sends it as the order's
 * vendor_name, and checks it on save the same way.
 *
 * A field-level skip, not an exception: the stored name stays, every sibling
 * field still saves, and the merchant is told why. A name saved before the
 * rule, posted back unchanged, is not refused: it is sent as it always was,
 * and refusing it would report an error on every save of the section.
 */
class VendorName extends Value
{
    public const MAX_LENGTH = 255;

    /**
     * @var MessageManager
     */
    private $messageManager;

    public function __construct(
        Context $context,
        Registry $registry,
        ScopeConfigInterface $config,
        TypeListInterface $cacheTypeList,
        MessageManager $messageManager,
        ?AbstractResource $resource = null,
        ?AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        parent::__construct($context, $registry, $config, $cacheTypeList, $resource, $resourceCollection, $data);
        $this->messageManager = $messageManager;
    }

    /** Whether a vendor name may be sent: printable ASCII, at most MAX_LENGTH characters. */
    public static function isSendable(string $name): bool
    {
        return preg_match('/^[\x20-\x7E]+\z/', $name) === 1 && strlen($name) <= self::MAX_LENGTH;
    }

    /**
     * @return $this
     */
    public function beforeSave()
    {
        if ($this->getData('inherit') || $this->getValue() === null) {
            return parent::beforeSave();
        }
        $name = trim((string)$this->getValue());
        if ($name !== '' && !self::isSendable($name) && $name !== trim((string)$this->getOldValue())) {
            $this->_dataSaveAllowed = false;
            $this->messageManager->addErrorMessage(__(
                'Enter a vendor name of at most 255 characters, without line breaks or accented letters. The previously saved vendor name was kept.'
            ));
            return $this;
        }
        $this->setValue($name);
        return parent::beforeSave();
    }
}
