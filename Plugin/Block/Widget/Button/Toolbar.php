<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace ABN\Gateway\Plugin\Block\Widget\Button;

use Magento\Backend\Block\Widget\Button\ButtonList;
use Magento\Backend\Block\Widget\Button\Toolbar as ToolbarContext;
use Magento\Framework\View\Element\AbstractBlock;
use Magento\Sales\Block\Adminhtml\Order\View;
use Magento\Sales\Model\Order;
use ABN\Gateway\Model\Two;

/**
 * Plugin for remove order buttons
 */
class Toolbar
{
    /**
     * @param ToolbarContext $toolbar
     * @param AbstractBlock $context
     * @param ButtonList $buttonList
     * @return array|null
     */
    public function beforePushButtons(
        ToolbarContext $toolbar,
        AbstractBlock $context,
        ButtonList $buttonList
    ) {
        if (!$context instanceof View) {
            return null;
        }

        $order = $context->getOrder();
        if (!$order) {
            return null;
        }

        if ($order->getPayment()->getMethod() === Two::CODE && $order->getState() === Order::STATE_PENDING_PAYMENT) {
            $buttonList->remove('order_hold');
            $buttonList->remove('void_payment');
            $buttonList->remove('accept_payment');
            $buttonList->remove('order_edit');
            $buttonList->remove('order_creditmemo');
            $buttonList->remove('order_ship');
        }

        return [$context, $buttonList];
    }
}
