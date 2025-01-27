<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Observer;

use Magento\Framework\Event\Observer;
use Magento\Payment\Observer\AbstractDataAssignObserver;
use Magento\Quote\Api\Data\PaymentInterface;

/**
 * Two Payment Data Assign Observer
 * Set additional information to payment info
 */
class DataAssignObserver extends AbstractDataAssignObserver
{
    private $additionalInformationList = [
        'companyName',
        'companyId',
        'project',
        'department',
        'orderNote',
        'poNumber',
        'multipleInvoiceEmails'
    ];

    /**
     * @param Observer $observer
     * @return $this
     */
    public function execute(Observer $observer): self
    {
        $data = $this->readDataArgument($observer);

        $additionalData = $data->getData(PaymentInterface::KEY_ADDITIONAL_DATA);
        if (!is_array($additionalData)) {
            return $this;
        }

        $paymentInfo = $this->readPaymentModelArgument($observer);

        foreach ($this->additionalInformationList as $additionalInformationKey) {
            if (isset($additionalData[$additionalInformationKey])) {
                $paymentInfo->setAdditionalInformation(
                    $additionalInformationKey,
                    $additionalData[$additionalInformationKey]
                );
            }
        }

        return $this;
    }
}
