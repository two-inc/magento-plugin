<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Block\Payment;

use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Framework\DataObject;
use Magento\Framework\View\Element\Template\Context;
use Magento\Payment\Block\Info as PaymentInfo;
use Two\Gateway\Api\BrandRegistryInterface;

/**
 * Adds the brand-resolved Two order id as a row in Magento's native
 * admin order-view "Payment Information" section. Bound as the
 * `two_payment` / brand-overlay payment methods' `_infoBlockType`
 * (see Model\Two), so every brand gets its own "<brand> order id"
 * label via BrandRegistryInterface with no per-brand override.
 *
 * Admin-only: the same `_infoBlockType` also serves the frontend
 * customer order view, and the order id is not buyer-facing there.
 */
class Info extends PaymentInfo
{
    public function __construct(
        Context $context,
        private readonly BrandRegistryInterface $brandRegistry,
        private readonly State $appState,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    protected function _prepareSpecificInformation($transport = null): DataObject
    {
        $transport = parent::_prepareSpecificInformation($transport);

        if ($this->appState->getAreaCode() !== Area::AREA_ADMINHTML) {
            return $transport;
        }

        $orderId = (string)$this->getInfo()->getOrder()->getTwoOrderId();
        if ($orderId === '') {
            return $transport;
        }

        $label = (string)__('%1 order id', $this->brandRegistry->getProductName());
        $transport->setData($label, $orderId);

        return $transport;
    }
}
