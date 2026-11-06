<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Plugin\Block\Widget\Button;

use Magento\Backend\Block\Widget\Button\ButtonList;
use Magento\Backend\Block\Widget\Button\Toolbar as ToolbarContext;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\AbstractBlock;
use Magento\Sales\Block\Adminhtml\Order\View;
use Magento\Sales\Model\Order;
use Two\Gateway\Api\BrandOverlayRegistryInterface;
use Two\Gateway\Api\BrandRegistryInterface;
use Two\Gateway\Controller\Adminhtml\Order\CheckStatus;

/**
 * Order view toolbar while a Two order waits for the buyer: removes the
 * buttons that cannot apply yet, and adds Check status (TWO-26300).
 */
class Toolbar
{
    public const CHECK_STATUS_BUTTON = 'two_check_status';

    private $overlayRegistry;

    /** @var BrandRegistryInterface */
    private $brandRegistry;

    /** @var AuthorizationInterface */
    private $authorization;

    /** @var UrlInterface */
    private $urlBuilder;

    public function __construct(
        BrandOverlayRegistryInterface $overlayRegistry,
        BrandRegistryInterface $brandRegistry,
        AuthorizationInterface $authorization,
        UrlInterface $urlBuilder
    ) {
        $this->overlayRegistry = $overlayRegistry;
        $this->brandRegistry = $brandRegistry;
        $this->authorization = $authorization;
        $this->urlBuilder = $urlBuilder;
    }

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

        $isTwoStack = $this->overlayRegistry->isTwoStackMethod((string)$order->getPayment()->getMethod());
        if ($isTwoStack && $order->getState() === Order::STATE_PENDING_PAYMENT) {
            $buttonList->remove('order_hold');
            $buttonList->remove('void_payment');
            $buttonList->remove('accept_payment');
            $buttonList->remove('order_edit');
            $buttonList->remove('order_creditmemo');
            $buttonList->remove('order_ship');
            if ($order->getTwoOrderId() && $this->authorization->isAllowed(CheckStatus::ADMIN_RESOURCE)) {
                $buttonList->add(self::CHECK_STATUS_BUTTON, [
                    'label' => __('Check status with %1', $this->brandRegistry->getProductName()),
                    'class' => 'two-check-status',
                    'onclick' => $this->postOnClick(
                        $this->urlBuilder->getUrl('two/order/checkStatus', ['order_id' => $order->getId()])
                    ),
                ]);
            }
        }

        return [$context, $buttonList];
    }

    /**
     * Submit a POST carrying the admin form key, since the check changes the
     * order. The button block escapes the attribute.
     */
    private function postOnClick(string $url): string
    {
        return 'var f=document.createElement("form");f.method="post";f.action='
            . json_encode($url, JSON_UNESCAPED_SLASHES)
            . ';var k=document.createElement("input");k.type="hidden";k.name="form_key";k.value=window.FORM_KEY;'
            . 'f.appendChild(k);document.body.appendChild(f);f.submit();';
    }
}
